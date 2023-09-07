<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InventoryPlannerProductXLS implements FromArray, WithHeadings
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
            'product_id',//A
            'title',//B
            'SKU',//C
            'regular_price',//D
            'price',//E
            'stock_quantity',//F
            'created_at',//G
            'updated_at',//H
            'managing_stock',//I
            'vendor',//J
            'vendor_product_name',//K
            'visible',//L
            'categories',//M
            'image',//N
            'barcode',//O
            'brand',//P
            'options',//Q
            'tags',//R
            'removed',//S
            // Add more column headings as needed
        ];
    }
}