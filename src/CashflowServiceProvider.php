<?php

declare(strict_types=1);

namespace Laratusk\Cashflow;

use Illuminate\Support\ServiceProvider;
use Laratusk\Cashflow\Commands\MakeBalanceItemCommand;

final class CashflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cashflow.php',
            'cashflow',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeBalanceItemCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/cashflow.php' => config_path('cashflow.php'),
        ], 'cashflow-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cashflow-migrations');
    }
}
