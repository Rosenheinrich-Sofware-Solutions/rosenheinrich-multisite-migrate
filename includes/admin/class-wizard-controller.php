<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Wizard_Controller
{
    /**
     * @param array<int,array{slug:string,label:string}> $steps
     */
    public static function current_step(array $steps, string $requested): string
    {
        $slugs = array_column($steps, 'slug');
        if (in_array($requested, $slugs, true)) {
            return $requested;
        }
        return $slugs[0] ?? 'welcome';
    }

    /**
     * @param array<int,array{slug:string,label:string}> $steps
     */
    public static function step_index(array $steps, string $current): int
    {
        foreach ($steps as $i => $step) {
            if ($step['slug'] === $current) {
                return $i;
            }
        }
        return 0;
    }
}
