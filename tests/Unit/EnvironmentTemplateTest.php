<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Environment\EnvironmentTemplate;
use PickeringTech\Harbour\Exceptions\HarbourException;

final class EnvironmentTemplateTest extends TestCase
{
    public function test_it_only_interpolates_the_v1_syntax_literally(): void
    {
        $template = new EnvironmentTemplate;

        self::assertSame(
            "APP_URL=http://127.0.0.1:8123\nSHELL=\${VALUE:-no}\nVALUE=a&b$'c\n",
            $template->render("APP_URL=http://127.0.0.1:\${APP_PORT}\nSHELL=\${VALUE:-no}\nVALUE=\${VALUE}\n", [
                'APP_PORT' => '8123',
                'VALUE' => "a&b$'c",
            ]),
        );
    }

    public function test_it_reports_unique_required_variable_names(): void
    {
        self::assertSame(
            ['APP_PORT', 'APP_KEY'],
            (new EnvironmentTemplate)->variables("PORT=\${APP_PORT}\nKEY=\${APP_KEY}\nAGAIN=\${APP_PORT}\n"),
        );
    }

    public function test_it_rejects_unresolved_variables(): void
    {
        $this->expectException(HarbourException::class);
        $this->expectExceptionMessage('MISSING');

        (new EnvironmentTemplate)->render('VALUE=${MISSING}', []);
    }
}
