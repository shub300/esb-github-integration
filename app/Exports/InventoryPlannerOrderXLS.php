<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InventoryPlannerOrderXLS implements FromArray, WithHeadings
{
    protected $dataArray;

    public function __construct(array $dataArray)
    {
        $this->dataArray = $dataArray;
    }

    public function array(): array
    {
        return $this->dataArray;
    }

    public function headings(): array
    {
        return [
            'order_number',
            'date',
            'price',
            'quantity',
            'product_id',
            'SKU',
            'discount',
            'tax',
            'tax_included',
            'shipping',
            'customer',
            'currency',
            'canceled',
            'warehouse',
            'updated_at',
            // Add more column headings as needed
        ];
    }
}