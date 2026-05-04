<?php

namespace SportBoeker\PersonalizedSort\Contracts;

interface Signal
{
    public function name(): string;
    public function weight(): float;
    public function decay(): DecayStrategy;
    public function source(): SignalSource;
}
