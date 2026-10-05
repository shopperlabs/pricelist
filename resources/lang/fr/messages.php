<?php

declare(strict_types=1);

return [
    'title' => 'Listes de prix',
    'single' => 'Liste de prix',
    'description' => 'Proposez des prix promotionnels ou de remplacement à des clients ciblés, pour une période ou à partir d\'une quantité.',
    'menu' => 'Listes de prix',
    'empty' => 'Aucune liste de prix pour le moment. Créez-en une pour cibler vos prix.',
    'created' => 'Liste de prix créée',
    'updated' => 'Liste de prix mise à jour',

    'actions' => [
        'add' => 'Ajouter une liste de prix',
        'archive' => 'Archiver',
        'simulate' => 'Simuler',
        'add_products' => 'Ajouter des produits',
        'remove' => 'Retirer de la liste',
        'remove_selected' => 'Retirer (:count)',
        'apply' => 'Appliquer',
        'add_tier' => 'Ajouter un palier',
        'add_tier_short' => '+ Palier',
        'add_rule' => '+ Règle',
    ],

    'tabs' => [
        'all' => 'Toutes',
    ],

    'wizard' => [
        'details' => 'Informations',
        'target' => 'Cible',
        'pricing' => 'Tarification',
        'create' => 'Créer la liste',
    ],

    'type_descriptions' => [
        'sale' => 'S\'applique seulement si elle baisse le prix du catalogue.',
        'override' => 'Remplace le prix du catalogue, même s\'il est plus élevé.',
    ],

    'priority_help' => 'Quand plusieurs listes s\'appliquent au même produit, la priorité la plus haute l\'emporte.',
    'target_intro' => 'Choisissez qui bénéficie de ces prix. Laissez tous les champs vides pour cibler tout le monde.',
    'target_summary' => 'Cette liste s\'applique à',
    'everyone' => 'Tout le monde',
    'summary_rule' => ':rule : :values',
    'summary_or' => ' ou ',
    'summary_and' => ', et ',

    'adjustment' => 'Ajustement',
    'adjustment_help' => 'Appliqué au prix du catalogue de chaque produit sans prix fixe dans cette liste.',
    'adjustment_value' => 'Pourcentage',
    'rounding_ending' => 'Terminaison en :currency',
    'rounding_example' => ':before devient :after',
    'compare_at' => 'Prix barré',
    'compare_at_mode' => 'Prix barré affiché',
    'compare_at_mode_descriptions' => [
        'keep' => 'Affiche le prix barré défini sur le produit.',
        'base' => 'Barre le prix du catalogue.',
        'lowest_30_days' => 'Barre le prix le plus bas des 30 jours précédant la réduction (règle Omnibus de l\'UE).',
        'none' => 'Affiche seulement les prix barrés saisis dans cette liste.',
    ],
    'tiers' => 'Paliers',
    'tier_scope' => 'Quantité des paliers',
    'tier_scope_descriptions' => [
        'item' => 'Chaque variante atteint ses paliers avec sa propre quantité.',
        'product' => 'Les quantités de toutes les variantes d\'un produit s\'additionnent pour atteindre un palier.',
    ],

    'target' => 'Cible',
    'period' => 'Période',
    'period_from' => 'À partir du :date',
    'period_until' => 'Jusqu\'au :date',
    'period_permanent' => 'Permanente',

    'prices' => 'Prix',
    'prices_saved' => 'Prix enregistrés',
    'prices_empty' => 'Cette liste ne contient aucun prix. Ajoutez des produits pour commencer.',
    'prices_no_match' => 'Aucun produit ne correspond à votre recherche.',
    'products_removed' => 'Produits retirés de la liste',
    'remove_description' => 'Leurs prix, paliers et règles de quantité dans cette liste seront supprimés. Les prix du catalogue ne changent pas.',
    'invalid_amount' => 'Saisissez un montant valide supérieur à zéro.',
    'unsaved_changes' => ':count modification non enregistrée|:count modifications non enregistrées',

    'search_placeholder' => 'Rechercher par nom ou SKU',
    'currencies' => 'Devises',
    'product_variant' => 'Produit / variante',
    'quantity' => 'Quantité',
    'select_all' => 'Sélectionner tous les produits de la page',
    'select_row' => 'Sélectionner :name',
    'toggle_variants' => 'Afficher les variantes de :name',
    'variants_count' => ':count variante, :priced avec prix|:count variantes, :priced avec prix',
    'no_variant' => 'Sans variante',
    'catalog_price' => 'Catalogue :amount',
    'inherited' => 'Héritée',

    'tiers_count' => ':count palier|:count paliers',
    'tiers_heading' => 'Paliers de :name en :currency',
    'tiers_scope' => 'Quantité des paliers : :scope.',
    'tiers_overlap' => 'Deux paliers couvrent les mêmes quantités.',
    'unit_price' => 'Prix unitaire',
    'compare_amount' => 'Prix barré',

    'quantity_min' => 'Min :count',
    'quantity_max' => 'Max :count',
    'quantity_minimum' => 'Minimum',
    'quantity_maximum' => 'Maximum',
    'quantity_increment' => 'Incrément',
    'quantity_increment_invalid' => 'Le minimum doit être un multiple de l\'incrément.',
    'quantity_rule_heading' => 'Règle de quantité pour :name',
    'quantity_rule_help' => 'Les clients de cette liste commandent entre le minimum et le maximum, par multiples de l\'incrément. La règle d\'un produit s\'applique aussi à ses variantes qui n\'ont pas la leur.',
    'quantity_rule_saved' => 'Règle de quantité enregistrée',

    'simulator' => [
        'heading' => 'Simuler un prix',
        'description' => 'Voyez le prix payé par un client et la liste qui le fixe.',
        'customer' => 'Client',
        'anonymous' => 'Visiteur',
        'zone' => 'Zone',
        'channel' => 'Canal',
        'amount' => 'Prix payé',
        'compare_amount' => 'Prix barré',
        'winner' => 'Liste gagnante',
        'this_list' => ':name (cette liste)',
        'catalog' => 'Aucune, le prix du catalogue s\'applique',
        'source' => 'Source',
        'source_fixed' => 'Prix fixe',
        'source_adjustment' => 'Ajustement de la liste',
        'level_product' => 'niveau produit',
        'level_variant' => 'niveau variante',
        'tier_range' => 'palier :min à :max',
        'tier_from' => 'palier :min et plus',
        'no_price' => 'Ce produit n\'a pas de prix dans cette devise.',
    ],

    'starts_at' => 'Débute le',
    'ends_at' => 'Se termine le',
    'priority' => 'Priorité',
    'min_quantity' => 'Quantité min',
    'max_quantity' => 'Quantité max',

    'types' => [
        'sale' => 'Promotion',
        'override' => 'Remplacement',
    ],

    'statuses' => [
        'draft' => 'Brouillon',
        'active' => 'Active',
        'archived' => 'Archivée',
    ],

    'states' => [
        'draft' => 'Brouillon',
        'scheduled' => 'Programmée',
        'active' => 'Active',
        'expired' => 'Expirée',
        'archived' => 'Archivée',
    ],

    'adjustments' => [
        'none' => 'Aucun',
        'decrease' => 'Baisse',
        'increase' => 'Hausse',
    ],

    'compare_at_modes' => [
        'keep' => 'Prix barré du catalogue',
        'base' => 'Prix du catalogue',
        'lowest_30_days' => 'Prix le plus bas des 30 derniers jours',
        'none' => 'Aucun prix barré',
    ],

    'tier_scopes' => [
        'item' => 'Par article',
        'product' => 'Sur toutes les variantes d\'un produit',
    ],

    'rules' => [
        'customer_group' => 'Groupes de clients',
        'customer_group_help' => 'Seuls les clients des groupes sélectionnés bénéficient de ces prix. Laissez vide pour cibler tout le monde.',
        'customer' => 'Clients',
        'channel' => 'Canaux',
        'channel_help' => 'Seules les requêtes envoyées avec l\'un de ces canaux obtiennent ces prix. Les requêtes sans canal ne les obtiennent pas.',
        'zone' => 'Zones',
    ],
];
