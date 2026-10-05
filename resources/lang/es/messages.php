<?php

declare(strict_types=1);

return [
    'title' => 'Listas de precios',
    'single' => 'Lista de precios',
    'description' => 'Ofrezca precios de oferta o de reemplazo a clientes específicos, por un período o a partir de una cantidad.',
    'menu' => 'Listas de precios',
    'empty' => 'Aún no hay listas de precios. Cree una para empezar a segmentar sus precios.',
    'created' => 'Lista de precios creada',
    'updated' => 'Lista de precios actualizada',

    'actions' => [
        'add' => 'Añadir lista de precios',
        'archive' => 'Archivar',
        'simulate' => 'Simular',
        'add_products' => 'Añadir productos',
        'remove' => 'Quitar de la lista',
        'remove_selected' => 'Quitar (:count)',
        'apply' => 'Aplicar',
        'add_tier' => 'Añadir un tramo',
        'add_tier_short' => '+ Tramo',
        'add_rule' => '+ Regla',
    ],

    'tabs' => [
        'all' => 'Todas',
    ],

    'wizard' => [
        'details' => 'Información',
        'target' => 'Destinatarios',
        'pricing' => 'Tarificación',
        'create' => 'Crear la lista',
    ],

    'type_descriptions' => [
        'sale' => 'Solo se aplica si baja el precio del catálogo.',
        'override' => 'Reemplaza el precio del catálogo, aunque sea más alto.',
    ],

    'priority_help' => 'Cuando varias listas se aplican al mismo producto, gana la prioridad más alta.',
    'target_intro' => 'Elija quién obtiene estos precios. Deje todos los campos vacíos para dirigirse a todos.',
    'target_summary' => 'Esta lista se aplica a',
    'everyone' => 'Todos',
    'summary_rule' => ':rule: :values',
    'summary_or' => ' o ',
    'summary_and' => ', y ',

    'adjustment' => 'Ajuste',
    'adjustment_help' => 'Se aplica al precio del catálogo de cada producto sin precio fijo en esta lista.',
    'adjustment_value' => 'Porcentaje',
    'rounding_ending' => 'Terminación en :currency',
    'rounding_example' => ':before pasa a :after',
    'compare_at' => 'Precio tachado',
    'compare_at_mode' => 'Precio tachado mostrado',
    'compare_at_mode_descriptions' => [
        'keep' => 'Muestra el precio tachado definido en el producto.',
        'base' => 'Tacha el precio del catálogo.',
        'lowest_30_days' => 'Tacha el precio más bajo de los 30 días previos al descuento (norma Ómnibus de la UE).',
        'none' => 'Muestra solo los precios tachados introducidos en esta lista.',
    ],
    'tiers' => 'Tramos',
    'tier_scope' => 'Cantidad de los tramos',
    'tier_scope_descriptions' => [
        'item' => 'Cada variante alcanza sus tramos con su propia cantidad.',
        'product' => 'Las cantidades de todas las variantes de un producto se suman para alcanzar un tramo.',
    ],

    'target' => 'Destinatarios',
    'period' => 'Período',
    'period_from' => 'Desde el :date',
    'period_until' => 'Hasta el :date',
    'period_permanent' => 'Permanente',

    'prices' => 'Precios',
    'prices_saved' => 'Precios guardados',
    'prices_empty' => 'Esta lista no contiene precios. Añada productos para empezar.',
    'prices_no_match' => 'Ningún producto coincide con su búsqueda.',
    'products_removed' => 'Productos quitados de la lista',
    'remove_description' => 'Se eliminarán sus precios, tramos y reglas de cantidad en esta lista. Los precios del catálogo no cambian.',
    'invalid_amount' => 'Introduzca un importe válido mayor que cero.',
    'unsaved_changes' => ':count cambio sin guardar|:count cambios sin guardar',

    'search_placeholder' => 'Buscar por nombre o SKU',
    'currencies' => 'Monedas',
    'product_variant' => 'Producto / variante',
    'quantity' => 'Cantidad',
    'select_all' => 'Seleccionar todos los productos de la página',
    'select_row' => 'Seleccionar :name',
    'toggle_variants' => 'Mostrar las variantes de :name',
    'variants_count' => ':count variante, :priced con precio|:count variantes, :priced con precio',
    'no_variant' => 'Sin variante',
    'catalog_price' => 'Catálogo :amount',
    'inherited' => 'Heredada',

    'tiers_count' => ':count tramo|:count tramos',
    'tiers_heading' => 'Tramos de :name en :currency',
    'tiers_scope' => 'Cantidad de los tramos: :scope.',
    'tiers_overlap' => 'Dos tramos cubren las mismas cantidades.',
    'unit_price' => 'Precio unitario',
    'compare_amount' => 'Precio tachado',

    'quantity_min' => 'Mín :count',
    'quantity_max' => 'Máx :count',
    'quantity_minimum' => 'Mínimo',
    'quantity_maximum' => 'Máximo',
    'quantity_increment' => 'Incremento',
    'quantity_increment_invalid' => 'El mínimo debe ser un múltiplo del incremento.',
    'quantity_rule_heading' => 'Regla de cantidad para :name',
    'quantity_rule_help' => 'Los clientes de esta lista piden entre el mínimo y el máximo, en múltiplos del incremento. La regla de un producto también se aplica a sus variantes que no tienen la suya.',
    'quantity_rule_saved' => 'Regla de cantidad guardada',

    'simulator' => [
        'heading' => 'Simular un precio',
        'description' => 'Vea el precio que paga un cliente y la lista que lo fija.',
        'customer' => 'Cliente',
        'anonymous' => 'Visitante',
        'zone' => 'Zona',
        'channel' => 'Canal',
        'amount' => 'Precio pagado',
        'compare_amount' => 'Precio tachado',
        'winner' => 'Lista ganadora',
        'this_list' => ':name (esta lista)',
        'catalog' => 'Ninguna, se aplica el precio del catálogo',
        'source' => 'Origen',
        'source_fixed' => 'Precio fijo',
        'source_adjustment' => 'Ajuste de la lista',
        'level_product' => 'nivel producto',
        'level_variant' => 'nivel variante',
        'tier_range' => 'tramo :min a :max',
        'tier_from' => 'tramo :min o más',
        'no_price' => 'Este producto no tiene precio en esta moneda.',
    ],

    'starts_at' => 'Comienza el',
    'ends_at' => 'Termina el',
    'priority' => 'Prioridad',
    'min_quantity' => 'Cantidad mín',
    'max_quantity' => 'Cantidad máx',

    'types' => [
        'sale' => 'Oferta',
        'override' => 'Reemplazo',
    ],

    'statuses' => [
        'draft' => 'Borrador',
        'active' => 'Activa',
        'archived' => 'Archivada',
    ],

    'states' => [
        'draft' => 'Borrador',
        'scheduled' => 'Programada',
        'active' => 'Activa',
        'expired' => 'Caducada',
        'archived' => 'Archivada',
    ],

    'adjustments' => [
        'none' => 'Ninguno',
        'decrease' => 'Disminución',
        'increase' => 'Aumento',
    ],

    'compare_at_modes' => [
        'keep' => 'Precio tachado del catálogo',
        'base' => 'Precio del catálogo',
        'lowest_30_days' => 'Precio más bajo de los últimos 30 días',
        'none' => 'Sin precio tachado',
    ],

    'tier_scopes' => [
        'item' => 'Por artículo',
        'product' => 'En todas las variantes de un producto',
    ],

    'rules' => [
        'customer_group' => 'Grupos de clientes',
        'customer_group_help' => 'Solo los clientes de los grupos seleccionados obtienen estos precios. Déjelo vacío para dirigirse a todos.',
        'customer' => 'Clientes',
        'channel' => 'Canales',
        'channel_help' => 'Solo las solicitudes enviadas con uno de estos canales obtienen estos precios. Las solicitudes sin canal no.',
        'zone' => 'Zonas',
    ],
];
