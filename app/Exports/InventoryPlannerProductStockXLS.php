<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Collection;

class InventoryPlannerProductStockXLS implements ToCollection
{
    /**
     * 0 => "variant_id"
     * 1 => "product_id"
     * 2 => "title"
     * 3 => "SKU"
     * 4 => "regular_price"
     * 5 => "price"
     * 6 => "stock_quantity"
     * 7 => "created_at"
     * 8 => "updated_at"
     * 9 => "managing_stock"
     * 10 => "vendor"
     * 11 => "permalink"
     * 12 => "categories"
     * 13 => "image"
     * 14 => "brand"
     * 15 => "options"
     * 16 => "tags"
     */
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if ( $row[0] == 'KT-TEST-20230708-02') {
                // Update the values in the row as needed
                $row[10] = 'Gautam Kakadiya';
                    // ... other columns you want to update
            }
        }
    }
}