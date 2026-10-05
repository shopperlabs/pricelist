<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Shopper\Cart\CartManager;
use Shopper\Cart\Exceptions\CartCompletedException;
use Shopper\Cart\Exceptions\MissingPriceException;
use Shopper\Cart\Exceptions\PriceChangedException;
use Shopper\Cart\Exceptions\QuantityRuleViolationException;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Contracts\QuantityRuleResolver;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Models\TaxRate;
use Shopper\Core\Models\TaxZone;
use Shopper\Core\Models\Zone;
use Shopper\Core\Pricing\PricingContext;
use Shopper\CustomerGroups\Models\CustomerGroup;
use Shopper\PriceList\Enum\TierScope;
use Shopper\PriceList\Models\PriceList;
use Shopper\PriceList\Models\PriceListQuantityRule;
use Shopper\PriceList\Models\PriceListRule;
use Shopper\PriceList\Tests\Stubs\User;

beforeEach(function (): void {
    $this->cart = Cart::factory()->create();
    $this->manager = resolve(CartManager::class);
    $this->product = stocked(withCatalogPrice(Product::factory()->create(), 2000));
});

it('reprices a line whose quantity crosses a tier and keeps the list on the line', function (): void {
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1500, ['min_quantity' => 10]);

    $line = $this->manager->add($this->cart, $this->product, 9);

    expect($line->unit_price_amount)->toBe(2000);

    $line = $this->manager->update($this->cart, $line->id, ['quantity' => 10]);

    expect($line->unit_price_amount)->toBe(1500)
        ->and($line->pricing['price_list']['id'])->toBe($list->public_id)
        ->and($line->pricing['tier'])->toEqual([10, null]);
});

it('gives a group member the group price once the cart is theirs', function (): void {
    $member = User::factory()->create();
    $group = CustomerGroup::factory()->create();
    $group->customers()->attach($member);
    $list = PriceList::factory()->override()->create();
    listPrice($list, $this->product, 1600);
    PriceListRule::factory()->for($list)->create(['rule_key' => 'customer_group', 'values' => [$group->id]]);

    $line = $this->manager->add($this->cart, $this->product);
    $cart = $this->manager->transfer($this->cart, $member->id);

    expect($line->unit_price_amount)->toBe(2000)
        ->and($cart->lines()->sole()->unit_price_amount)->toBe(1600);
});

it('never reprices a completed cart', function (): void {
    $this->manager->add($this->cart, $this->product);
    $this->cart->update(['completed_at' => now()]);
    listPrice(PriceList::factory()->create(), $this->product, 1500);

    expect(fn () => $this->manager->reprice($this->cart))->toThrow(CartCompletedException::class)
        ->and($this->cart->lines()->sole()->unit_price_amount)->toBe(2000);
});

it('keeps a manual price whatever the lists say', function (): void {
    $line = $this->manager->add($this->cart, $this->product);
    $this->manager->setLinePrice($this->cart, $line->id, 1234);
    listPrice(PriceList::factory()->create(), $this->product, 1500);

    $this->manager->reprice($this->cart);

    expect($line->refresh()->unit_price_amount)->toBe(1234);
});

it('refuses the checkout of a line whose sale ended after it was added', function (): void {
    listPrice(PriceList::factory()->create(['ends_at' => now()->addHour()]), $this->product, 1500);
    $this->manager->add($this->cart, $this->product);

    $this->travel(2)->hours();
    app()->forgetScopedInstances();

    expect(fn () => $this->manager->revalidate($this->cart->refresh()))->toThrow(PriceChangedException::class);
});

it('refuses the checkout of a list-only line whose list ended after it was added', function (): void {
    $product = stocked(Product::factory()->create());
    listPrice(PriceList::factory()->override()->create(['ends_at' => now()->addHour()]), $product, 1500);
    $this->manager->add($this->cart, $product);

    $this->travel(2)->hours();
    app()->forgetScopedInstances();

    expect(fn () => $this->manager->revalidate($this->cart->refresh()))->toThrow(MissingPriceException::class);
});

it('prices a line from the list of the zone the cart ships to', function (): void {
    $zone = Zone::factory()->create();
    $list = PriceList::factory()->create();
    listPrice($list, $this->product, 1600);
    PriceListRule::factory()->for($list)->create(['rule_key' => 'zone', 'values' => [$zone->id]]);

    expect($this->manager->add(Cart::factory()->create(['zone_id' => $zone->id]), $this->product)->unit_price_amount)->toBe(1600)
        ->and($this->manager->add($this->cart, $this->product)->unit_price_amount)->toBe(2000);
});

it('treats a list price in the tax convention of the zone, like a catalog price', function (): void {
    $country = Country::factory()->create(['cca2' => 'FR', 'name' => 'France']);
    TaxRate::factory()->create([
        'tax_zone_id' => TaxZone::factory()->create(['country_id' => $country->id, 'is_tax_inclusive' => true])->id,
        'rate' => 20.00,
        'is_default' => true,
    ]);
    listPrice(PriceList::factory()->create(), $this->product, 1200);

    $this->manager->add($this->cart, $this->product);
    $this->manager->addAddress($this->cart, AddressType::Shipping, [
        'first_name' => 'Jeanne',
        'last_name' => 'Martin',
        'address_1' => '1 rue de Rivoli',
        'city' => 'Paris',
        'postal_code' => '75001',
        'country_id' => $country->id,
    ]);

    $totals = $this->manager->calculate($this->cart->refresh());

    expect($totals->taxInclusive)->toBeTrue()
        ->and($totals->total)->toBe(1200)
        ->and($totals->taxTotal)->toBe(200);
});

describe('tiers across the variants of a product', function (): void {
    beforeEach(function (): void {
        $parent = Product::factory()->variant()->create();
        $this->small = stocked(withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 2000));
        $this->large = stocked(withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 2000));
        $this->list = PriceList::factory()->create(['tier_scope' => TierScope::Product]);
        listPrice($this->list, $parent, 1500, ['min_quantity' => 6]);
    });

    it('reaches a product tier with several variants and loses it when one is removed', function (): void {
        $small = $this->manager->add($this->cart, $this->small, 3);
        $large = $this->manager->add($this->cart, $this->large, 3);

        expect($small->refresh()->unit_price_amount)->toBe(1500)
            ->and($large->unit_price_amount)->toBe(1500);

        $this->manager->remove($this->cart, $large->id);

        expect($small->refresh()->unit_price_amount)->toBe(2000);
    });

    it('keeps an item tier per variant', function (): void {
        $this->list->update(['tier_scope' => TierScope::Item]);

        $small = $this->manager->add($this->cart, $this->small, 3);
        $this->manager->add($this->cart, $this->large, 3);

        expect($small->refresh()->unit_price_amount)->toBe(2000);
    });
});

describe('quantity rules', function (): void {
    beforeEach(function (): void {
        $this->list = PriceList::factory()->create();
        listPrice($this->list, $this->product, 1500);
    });

    it('enforces the minimum, maximum and increment of the list', function (int $quantity, bool $allowed): void {
        PriceListQuantityRule::factory()->for($this->list)->for($this->product, 'priceable')->create([
            'minimum' => 12,
            'maximum' => 48,
            'increment' => 6,
        ]);

        $add = fn () => $this->manager->add($this->cart, $this->product, $quantity);

        $allowed ? expect($add()->quantity)->toBe($quantity) : expect($add)->toThrow(QuantityRuleViolationException::class);
    })->with([
        'below the minimum' => [6, false],
        'not a multiple' => [15, false],
        'above the maximum' => [54, false],
        'allowed' => [18, true],
    ]);

    it('takes the quantity rule of the list that sets the price', function (): void {
        $customer = User::factory()->create();
        $rules = resolve(QuantityRuleResolver::class);
        PriceListQuantityRule::factory()->for($this->list)->for($this->product, 'priceable')->create(['minimum' => 6, 'increment' => 6]);
        $contract = PriceList::factory()->override()->create(['priority' => 10]);
        listPrice($contract, $this->product, 1400);
        PriceListRule::factory()->for($contract)->create(['rule_key' => 'customer', 'values' => [$customer->id]]);

        expect($rules->resolve($this->product, new PricingContext('USD'))->minimum)->toBe(6)
            ->and($rules->resolve($this->product, new PricingContext('USD', $customer->id)))->toBeNull();

        $flash = PriceList::factory()->create(['priority' => 20]);
        listPrice($flash, $this->product, 1450);
        PriceListQuantityRule::factory()->for($flash)->for($this->product, 'priceable')->create(['maximum' => 2]);
        app()->forgetScopedInstances();

        expect($rules->resolve($this->product, new PricingContext('USD'))->maximum)->toBe(2)
            ->and($rules->resolve($this->product, new PricingContext('USD', $customer->id)))->toBeNull();
    });

    it('sees a quantity rule changed earlier in the same request', function (): void {
        $rule = PriceListQuantityRule::factory()->for($this->list)->for($this->product, 'priceable')->create(['minimum' => 6]);
        $rules = resolve(QuantityRuleResolver::class);
        $rules->resolve($this->product, new PricingContext('USD'));

        $rule->update(['minimum' => 12]);

        expect($rules->resolve($this->product, new PricingContext('USD'))->minimum)->toBe(12);
    });

    it('reports a quantity rule it cannot apply and falls back to the catalog rule', function (): void {
        Exceptions::fake();
        PriceListQuantityRule::factory()->for($this->list)->for($this->product, 'priceable')->create(['minimum' => 5, 'increment' => 3]);

        expect($this->manager->add($this->cart, $this->product, 4)->quantity)->toBe(4);

        Exceptions::assertReported(InvalidArgumentException::class);
    });

    it('lets the rule of a variant win over the rule of its product', function (): void {
        $parent = Product::factory()->variant()->create();
        $variant = stocked(withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 2000));
        $sibling = stocked(withCatalogPrice(ProductVariant::factory()->for($parent)->create(), 2000));
        listPrice($this->list, $parent, 1500);
        PriceListQuantityRule::factory()->for($this->list)->for($parent, 'priceable')->create(['minimum' => 12, 'increment' => 12]);
        PriceListQuantityRule::factory()->for($this->list)->for($variant, 'priceable')->create(['minimum' => 2]);

        expect($this->manager->add($this->cart, $variant, 2)->quantity)->toBe(2)
            ->and(fn () => $this->manager->add($this->cart, $sibling, 2))->toThrow(QuantityRuleViolationException::class);
    });

    it('exempts a manually priced line', function (): void {
        $line = $this->manager->add($this->cart, $this->product);
        $this->manager->setLinePrice($this->cart, $line->id, 1000);
        PriceListQuantityRule::factory()->for($this->list)->for($this->product, 'priceable')->create(['minimum' => 12]);

        $this->manager->assertQuantityRules($this->cart->refresh());

        expect($line->refresh()->quantity)->toBe(1);
    });

    it('checks the rule of a group again once the cart belongs to a member', function (): void {
        $member = User::factory()->create();
        $group = CustomerGroup::factory()->create();
        $group->customers()->attach($member);
        PriceListRule::factory()->for($this->list)->create(['rule_key' => 'customer_group', 'values' => [$group->id]]);
        PriceListQuantityRule::factory()->for($this->list)->for($this->product, 'priceable')->create(['minimum' => 12]);

        $this->manager->add($this->cart, $this->product, 3);
        $cart = $this->manager->transfer($this->cart, $member->id);

        expect(fn () => $this->manager->assertQuantityRules($cart->refresh()))->toThrow(QuantityRuleViolationException::class);
    });
});
