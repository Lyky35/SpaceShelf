<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Services\ObjectService;

// Note: global classes (Exception, RuntimeException) are referenced fully
// qualified because migration files have no namespace and cannot import
// non-compound names.

return new class extends Migration
{
    /**
     * Charge pending building queue items that were created before the build queue
     * started charging resources at queue time (vanilla OGame behaviour).
     *
     * Items that were already under construction (building = 1) were charged at
     * build start by the previous code and already store their price, so they are
     * left untouched. Waiting (unstarted) items were never charged, so they are
     * charged now at the price their stored target level dictates. If the planet
     * can no longer cover the cost, the item is canceled instead; canceled items
     * keep a zero price so they refund nothing.
     */
    public function up(): void
    {
        $pending_items = DB::table('building_queues')
            ->where('canceled', 0)
            ->where('processed', 0)
            ->where('building', 0)
            ->orderBy('id')
            ->get();

        if ($pending_items->isEmpty()) {
            return;
        }

        $planet_service_factory = resolve(PlanetServiceFactory::class);

        /** @var array<int, \OGame\Services\PlanetService|null> $planets */
        $planets = [];

        foreach ($pending_items as $item) {
            if (!array_key_exists($item->planet_id, $planets)) {
                $planets[$item->planet_id] = $planet_service_factory->make((int)$item->planet_id);
            }
            $planet = $planets[$item->planet_id];

            if ($planet === null) {
                DB::table('building_queues')->where('id', $item->id)->update(['canceled' => 1]);
                continue;
            }

            try {
                $object = ObjectService::getObjectById((int)$item->object_id);
            } catch (\Exception) {
                DB::table('building_queues')->where('id', $item->id)->update(['canceled' => 1]);
                continue;
            }

            if ($item->is_downgrade) {
                // The level torn down is the one above the stored target level.
                $price = ObjectService::getObjectDowngradePrice(
                    $object->machine_name,
                    $planet,
                    ((int)$item->object_level_target) + 1
                );
            } elseif ((int)$item->object_level_target > $planet->getObjectLevel($object->machine_name)) {
                $price = ObjectService::getObjectRawPrice($object->machine_name, (int)$item->object_level_target);
            } else {
                // Stale upgrade target that the build queue would cancel when it
                // tries to start the item anyway; nothing was ever charged for it.
                DB::table('building_queues')->where('id', $item->id)->update(['canceled' => 1]);
                continue;
            }

            try {
                $planet->deductResources($price);
            } catch (\RuntimeException) {
                // The planet can no longer cover the queued cost, so cancel the item.
                DB::table('building_queues')->where('id', $item->id)->update(['canceled' => 1]);
                continue;
            }

            DB::table('building_queues')->where('id', $item->id)->update([
                'metal' => $price->metal->get(),
                'crystal' => $price->crystal->get(),
                'deuterium' => $price->deuterium->get(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data-only migration: charges applied by up() cannot be safely reversed.
    }
};
