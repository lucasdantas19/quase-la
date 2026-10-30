<?php

use App\Game\Temperature;

it('maps the complete score range to temperature bands', function (int $score, string $expected) {
    expect(Temperature::fromScore($score))->toBe($expected);
})->with([[0, 'muito frio'], [19, 'muito frio'], [20, 'frio'], [39, 'frio'], [40, 'morno'], [59, 'morno'], [60, 'quente'], [79, 'quente'], [80, 'muito quente'], [100, 'muito quente']]);
