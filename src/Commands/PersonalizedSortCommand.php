<?php

namespace SportBoeker\PersonalizedSort\Commands;

use Illuminate\Console\Command;

class PersonalizedSortCommand extends Command
{
    public $signature = 'laravel-personalized-sort';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
