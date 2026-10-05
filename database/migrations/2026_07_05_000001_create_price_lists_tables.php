<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Shopper\Core\Helpers\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(shopper_table('price_lists'), function (Blueprint $table): void {
            $this->addCommonFields($table);
            $table->ulid('public_id')->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('status');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->string('adjustment_type')->nullable();
            $table->unsignedInteger('adjustment_value')->nullable();
            $table->string('compare_at_mode')->nullable();
            $table->string('tier_scope')->default('item');
            $table->json('rounding_endings')->nullable();
            $table->json('metadata')->nullable();

            $table->index(['status', 'starts_at']);
            $table->index(['status', 'ends_at']);
        });

        Schema::create(shopper_table('price_list_prices'), function (Blueprint $table): void {
            $this->addCommonFields($table);
            $this->addForeignKey($table, 'price_list_id', $this->getTableName('price_lists'), false);
            $table->morphs('priceable');
            $this->addForeignKey($table, 'currency_id', $this->getTableName('currencies'), false);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('compare_amount')->nullable();
            $table->unsignedInteger('min_quantity')->default(1);
            $table->unsignedInteger('max_quantity')->nullable();

            $table->unique(
                ['price_list_id', 'priceable_type', 'priceable_id', 'currency_id', 'min_quantity'],
                'price_list_prices_unique',
            );
        });

        Schema::create(shopper_table('price_list_rules'), function (Blueprint $table): void {
            $this->addCommonFields($table);
            $this->addForeignKey($table, 'price_list_id', $this->getTableName('price_lists'), false);
            $table->string('rule_key');
            $table->json('values');

            $table->unique(['price_list_id', 'rule_key']);
        });

        Schema::create(shopper_table('price_list_quantity_rules'), function (Blueprint $table): void {
            $this->addCommonFields($table);
            $this->addForeignKey($table, 'price_list_id', $this->getTableName('price_lists'), false);
            $table->morphs('priceable');
            $table->unsignedInteger('minimum')->default(1);
            $table->unsignedInteger('maximum')->nullable();
            $table->unsignedInteger('increment')->default(1);

            $table->unique(['price_list_id', 'priceable_type', 'priceable_id'], 'price_list_quantity_rules_unique');
        });

        Schema::create(shopper_table('price_list_product_prices'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('price_list_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('item_id');
            $this->addForeignKey($table, 'currency_id', $this->getTableName('currencies'), false);
            $table->unsignedInteger('amount')->nullable();

            $table->unique(['price_list_id', 'product_id', 'item_id', 'currency_id'], 'price_list_product_prices_unique');
            $table->index(['product_id', 'currency_id', 'price_list_id', 'item_id', 'amount'], 'price_list_product_prices_lookup');
        });

        Schema::create(shopper_table('price_list_price_history'), function (Blueprint $table): void {
            $table->id();
            $table->morphs('priceable');
            $this->addForeignKey($table, 'currency_id', $this->getTableName('currencies'), false);
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->unsignedBigInteger('price_list_id')->nullable();
            $table->unsignedInteger('amount');
            $table->unsignedInteger('reference_amount')->nullable();
            $table->dateTime('recorded_at')->index();

            $table->index(
                ['priceable_type', 'priceable_id', 'currency_id', 'zone_id', 'channel_id', 'id'],
                'price_list_price_history_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(shopper_table('price_list_price_history'));
        Schema::dropIfExists(shopper_table('price_list_product_prices'));
        Schema::dropIfExists(shopper_table('price_list_quantity_rules'));
        Schema::dropIfExists(shopper_table('price_list_rules'));
        Schema::dropIfExists(shopper_table('price_list_prices'));
        Schema::dropIfExists(shopper_table('price_lists'));
    }
};
