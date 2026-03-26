<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs;

use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Jobs\JobParamAssigner;
use DateTimeImmutable;
use Tests\TestCase;

class JobParamAssignerTest extends TestCase
{
    public function testCanAutoInjectJobParams(): void
    {
        $dateTime = new DateTimeImmutable('2025-10-24');
        $job = $this->provideJob();
        $job->handle($dateTime, 123);

        $this->assertEquals($dateTime, $job->test);
        $this->assertEquals(123, $job->test2);
    }

    public function testCanAttachParams(): void
    {
        $job = $this->provideJob();
        $job->attach('test');

        $this->assertEquals('test', $job->test3);
    }

    private function provideJob(): object
    {
        return new class
        {
            use JobParamAssigner;

            #[Assignable]
            public DateTimeImmutable $test;

            #[Assignable]
            public int $test2;

            #[Assignable]
            public string $test3;

            public function handle(DateTimeImmutable $test, int $test2)
            {
                $this->assignParams(func_get_args());
            }

            public function attach(string $test3)
            {
                $this->attachParams([
                    'test3' => $test3,
                    'test4' => 'test4',
                ]);
            }
        };
    }
}
