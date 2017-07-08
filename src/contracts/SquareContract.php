<?php

namespace Nikolag\Square\Contracts;

interface SquareContract
{
    public function locations();
    public function save();
    public function charge(float $amount, string $nonce, string $location_id = null);
    public function transactions(int $location_id, $begin_time = null, $end_time = null, $cursor = null, $sort_order = 'desc');
}