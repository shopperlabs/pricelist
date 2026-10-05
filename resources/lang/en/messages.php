<?php

declare(strict_types=1);

return [
    'title' => 'Price lists',
    'single' => 'Price list',
    'description' => 'Offer sale or override prices to targeted customers, for a period or from a quantity.',
    'menu' => 'Price lists',
    'empty' => 'No price list yet. Create one to start targeting your prices.',
    'created' => 'Price list created',
    'updated' => 'Price list updated',

    'actions' => [
        'add' => 'Add price list',
        'archive' => 'Archive',
        'simulate' => 'Simulate',
        'add_products' => 'Add products',
        'remove' => 'Remove from the list',
        'remove_selected' => 'Remove (:count)',
        'apply' => 'Apply',
        'add_tier' => 'Add a tier',
        'add_tier_short' => '+ Tier',
        'add_rule' => '+ Rule',
    ],

    'tabs' => [
        'all' => 'All',
    ],

    'wizard' => [
        'details' => 'Details',
        'target' => 'Target',
        'pricing' => 'Pricing',
        'create' => 'Create price list',
    ],

    'type_descriptions' => [
        'sale' => 'Applies only when it lowers the catalog price.',
        'override' => 'Replaces the catalog price, even when it is higher.',
    ],

    'priority_help' => 'When several lists apply to the same product, the highest priority wins.',
    'target_intro' => 'Choose who gets these prices. Leave every field empty to target everyone.',
    'target_summary' => 'This list applies to',
    'everyone' => 'Everyone',
    'summary_rule' => ':rule: :values',
    'summary_or' => ' or ',
    'summary_and' => ', and ',

    'adjustment' => 'Adjustment',
    'adjustment_help' => 'Applied to the catalog price of every product without a fixed price in this list.',
    'adjustment_value' => 'Percentage',
    'rounding_ending' => 'Ending in :currency',
    'rounding_example' => ':before becomes :after',
    'compare_at' => 'Compare-at price',
    'compare_at_mode' => 'Compare-at price shown',
    'compare_at_mode_descriptions' => [
        'keep' => 'Shows the compare-at price set on the product.',
        'base' => 'Strikes through the catalog price.',
        'lowest_30_days' => 'Strikes through the lowest price of the 30 days before the discount (EU Omnibus rule).',
        'none' => 'Shows only the compare-at prices entered in this list.',
    ],
    'tiers' => 'Tiers',
    'tier_scope' => 'Tier quantity',
    'tier_scope_descriptions' => [
        'item' => 'Each variant reaches its tiers on its own quantity.',
        'product' => 'The quantities of all variants of a product add up to reach a tier.',
    ],

    'target' => 'Target',
    'period' => 'Period',
    'period_from' => 'From :date',
    'period_until' => 'Until :date',
    'period_permanent' => 'Permanent',

    'prices' => 'Prices',
    'prices_saved' => 'Prices saved',
    'prices_empty' => 'This price list has no prices yet. Add products to get started.',
    'prices_no_match' => 'No product matches your search.',
    'products_removed' => 'Products removed from the list',
    'remove_description' => 'Their prices, tiers and quantity rules in this list will be deleted. Catalog prices are not affected.',
    'invalid_amount' => 'Enter a valid amount greater than zero.',
    'unsaved_changes' => ':count unsaved change|:count unsaved changes',

    'search_placeholder' => 'Search by name or SKU',
    'currencies' => 'Currencies',
    'product_variant' => 'Product / variant',
    'quantity' => 'Quantity',
    'select_all' => 'Select all products on this page',
    'select_row' => 'Select :name',
    'toggle_variants' => 'Show the variants of :name',
    'variants_count' => ':count variant, :priced priced|:count variants, :priced priced',
    'no_variant' => 'No variant',
    'catalog_price' => 'Catalog :amount',
    'inherited' => 'Inherited',

    'tiers_count' => ':count tier|:count tiers',
    'tiers_heading' => 'Tiers for :name in :currency',
    'tiers_scope' => 'Tier quantity: :scope.',
    'tiers_overlap' => 'Two tiers cover the same quantities.',
    'unit_price' => 'Unit price',
    'compare_amount' => 'Compare-at price',

    'quantity_min' => 'Min :count',
    'quantity_max' => 'Max :count',
    'quantity_minimum' => 'Minimum',
    'quantity_maximum' => 'Maximum',
    'quantity_increment' => 'Increment',
    'quantity_increment_invalid' => 'The minimum must be a multiple of the increment.',
    'quantity_rule_heading' => 'Quantity rule for :name',
    'quantity_rule_help' => 'Customers of this list order between the minimum and the maximum, by multiples of the increment. A product rule also applies to its variants without their own rule.',
    'quantity_rule_saved' => 'Quantity rule saved',

    'simulator' => [
        'heading' => 'Simulate a price',
        'description' => 'See the price a customer pays and the list that sets it.',
        'customer' => 'Customer',
        'anonymous' => 'Guest',
        'zone' => 'Zone',
        'channel' => 'Channel',
        'amount' => 'Price paid',
        'compare_amount' => 'Compare-at price',
        'winner' => 'Winning list',
        'this_list' => ':name (this list)',
        'catalog' => 'None, the catalog price applies',
        'source' => 'Source',
        'source_fixed' => 'Fixed price',
        'source_adjustment' => 'List adjustment',
        'level_product' => 'product level',
        'level_variant' => 'variant level',
        'tier_range' => 'tier :min to :max',
        'tier_from' => 'tier :min and more',
        'no_price' => 'This product has no price in this currency.',
    ],

    'starts_at' => 'Starts at',
    'ends_at' => 'Ends at',
    'priority' => 'Priority',
    'min_quantity' => 'Min quantity',
    'max_quantity' => 'Max quantity',

    'types' => [
        'sale' => 'Sale',
        'override' => 'Override',
    ],

    'statuses' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'archived' => 'Archived',
    ],

    'states' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'active' => 'Active',
        'expired' => 'Expired',
        'archived' => 'Archived',
    ],

    'adjustments' => [
        'none' => 'None',
        'decrease' => 'Decrease',
        'increase' => 'Increase',
    ],

    'compare_at_modes' => [
        'keep' => 'Catalog compare-at price',
        'base' => 'Catalog price',
        'lowest_30_days' => 'Lowest price of the last 30 days',
        'none' => 'No compare-at price',
    ],

    'tier_scopes' => [
        'item' => 'Per item',
        'product' => 'Across the variants of a product',
    ],

    'rules' => [
        'customer_group' => 'Customer groups',
        'customer_group_help' => 'Only customers in the selected groups get these prices. Leave empty to target everyone.',
        'customer' => 'Customers',
        'channel' => 'Channels',
        'channel_help' => 'Only requests sent with one of these channels get these prices. Requests without a channel do not.',
        'zone' => 'Zones',
    ],
];
