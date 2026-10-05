<?php

declare(strict_types=1);

namespace Shopper\PriceList\Sidebar;

use Shopper\Sidebar\AbstractAdminSidebar;
use Shopper\Sidebar\Contracts\Builder\Group;
use Shopper\Sidebar\Contracts\Builder\Item;
use Shopper\Sidebar\Contracts\Builder\Menu;

final class PriceListSidebar extends AbstractAdminSidebar
{
    public function extendWith(Menu $menu): Menu
    {
        $menu->group(__('shopper::layout.sidebar.catalog'), function (Group $group): void {
            $group->item(__('shopper::pages/products.menu'), function (Item $item): void {
                $item->item(__('shopper-pricelist::messages.menu'), function (Item $child): void {
                    $child->weight(4);
                    $child->setAuthorized($this->user->hasPermissionTo('products.browse'));
                    $child->route('shopper.price-lists');
                    $child->useSpa();
                });
            });
        });

        return $menu;
    }
}
