<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class MakeBalanceItemCommand extends Command
{
    protected $signature = 'make:balance-item';

    protected $description = 'Create a new BalanceItem class';

    public function handle(Filesystem $files): int
    {
        $name = text(
            label: 'Class name',
            placeholder: 'e.g. Payment, GatewayFee, Refund',
            required: true,
        );

        $direction = select(
            label: 'Direction',
            options: ['debit' => 'Debit', 'credit' => 'Credit'],
        );

        $amountSource = select(
            label: 'How is the amount determined?',
            options: [
                'external' => 'Passed from outside (constructor parameter)',
                'fixed' => 'Fixed value (hardcoded)',
                'derived' => 'Calculated from another balance item (e.g. percentage)',
            ],
        );

        $fixedAmount = null;
        if ($amountSource === 'fixed') {
            $fixedAmount = (int) text(
                label: 'Fixed amount (in minor units, e.g. 2000 for $20.00)',
                required: true,
                validate: fn (string $v) => ctype_digit($v) && (int) $v > 0 ? null : 'Must be a positive integer.',
            );
        }

        $unique = confirm(
            label: 'Unique per reference? (#[Unique])',
            default: false,
        );

        $existingItems = $this->discoverBalanceItems($files);

        $requires = '';
        if ($existingItems !== []) {
            $options = ['' => 'None'] + array_combine($existingItems, array_map(class_basename(...), $existingItems));
            $requires = (string) select(
                label: 'Requires another BalanceItem?',
                options: $options,
            );
        }

        /** @var string $namespace */
        $namespace = config('cashflow.balance_items_namespace', 'App\\Ledgers');
        $relativePath = str_replace('\\', '/', str_replace('App\\', '', $namespace));
        $path = app_path("{$relativePath}/{$name}.php");

        $stub = $this->resolveStub((string) $amountSource, $files);
        $stub = $this->populateStub($stub, $namespace, $name, (string) $direction, $fixedAmount, $unique, $requires);

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $stub);

        $this->components->info("Created: {$relativePath}/{$name}.php");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function discoverBalanceItems(Filesystem $files): array
    {
        /** @var string $namespace */
        $namespace = config('cashflow.balance_items_namespace', 'App\\Ledgers');
        $relativePath = str_replace('\\', '/', str_replace('App\\', '', $namespace));
        $directory = app_path($relativePath);

        if (! $files->isDirectory($directory)) {
            return [];
        }

        $items = [];
        foreach ($files->files($directory) as $file) {
            $filename = $file->getFilenameWithoutExtension();
            $items[] = "{$namespace}\\{$filename}";
        }

        sort($items);

        return $items;
    }

    private function resolveStub(string $amountSource, Filesystem $files): string
    {
        return $files->get(dirname(__DIR__, 2)."/stubs/balance-item.{$amountSource}.stub");
    }

    private function populateStub(
        string $stub,
        string $namespace,
        string $name,
        string $direction,
        ?int $fixedAmount,
        bool $unique,
        string $requires,
    ): string {
        $uses = [
            'Laratusk\\Cashflow\\Contracts\\BalanceItem',
            'Laratusk\\Cashflow\\Enums\\Direction',
        ];

        $classAttributes = [];

        if (str_contains($stub, '#[Rule')) {
            $uses[] = 'Laratusk\\Cashflow\\Attributes\\Rule';
        }

        if ($unique) {
            $uses[] = 'Laratusk\\Cashflow\\Attributes\\Unique';
            $classAttributes[] = '#[Unique]';
        }

        if ($requires !== '') {
            $uses[] = 'Laratusk\\Cashflow\\Attributes\\Requires';
            $uses[] = $requires;
            $classAttributes[] = '#[Requires('.class_basename($requires).'::class)]';
        }

        sort($uses);
        $usesBlock = implode("\n", array_map(fn (string $u) => "use {$u};", array_unique($uses)));
        $attributesBlock = $classAttributes !== [] ? implode("\n", $classAttributes)."\n" : '';

        $directionValue = $direction === 'credit' ? 'Direction::Credit' : 'Direction::Debit';

        return str_replace(
            ['{{ namespace }}', '{{ uses }}', '{{ attributes }}', '{{ class }}', '{{ direction }}', '{{ amount }}'],
            [$namespace, $usesBlock, $attributesBlock, $name, $directionValue, (string) $fixedAmount],
            $stub,
        );
    }
}
