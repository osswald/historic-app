<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

final class NextcloudAreas
{
    /**
     * Unterordner nur anzeigen, wenn die Funktion schon benutzt wird und der Ordner da ist.
     *
     * @param list<NextcloudEntry> $children
     * @return list<NextcloudEntry>
     */
    public function select(bool $hasGastro, bool $hasRide, array $children): array
    {
        $gastro = null;
        $rides = null;
        foreach ($children as $child) {
            if (!$child->isCollection()) {
                continue;
            }
            if ($hasGastro && !$gastro instanceof NextcloudEntry && $child->name() === 'Gastronomie') {
                $gastro = $child;
            }
            if ($hasRide && !$rides instanceof NextcloudEntry && $child->name() === 'Fahrtenplanung') {
                $rides = $child;
            }
        }
        $selected = [];
        if ($gastro instanceof NextcloudEntry) {
            $selected[] = $gastro;
        }
        if ($rides instanceof NextcloudEntry) {
            $selected[] = $rides;
        }

        return $selected;
    }
}
