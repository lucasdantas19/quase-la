<?php

namespace App\Game;

class Temperature
{
    public static function fromScore(int $score): string
    {
        return match (true) {
            $score < 20 => 'muito frio', $score < 40 => 'frio', $score < 60 => 'morno', $score < 80 => 'quente', default => 'muito quente',
        };
    }
}
