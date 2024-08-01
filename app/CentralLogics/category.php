<?php

namespace App\CentralLogics;

use App\Model\Category;
use App\Model\Product;

class CategoryLogic
{
    public static function parents()
    {
        return Category::where('position', 0)->get();
    }

    public static function child($parent_id)
    {
        return Category::where(['parent_id' => $parent_id])->get();
    }

    public static function products($category_id, $product_type)
    {
        $products = Product::active()
            ->when(isset($product_type) && ($product_type == 'veg' || $product_type == 'meat' || $product_type == 'chicken' || $product_type == 'sea_food'), function ($query) use ($product_type) {
                return $query->productType($product_type);
            })
            ->get();
        $product_ids = [];
        foreach ($products as $product) {
            foreach (json_decode($product['category_ids'], true) as $category) {
                if ($category['id'] == $category_id) {
                    array_push($product_ids, $product['id']);
                }
            }
        }
        return Product::with('rating')->whereIn('id', $product_ids)->get();
    }

    public static function all_products($id)
    {
        $cate_ids=[];
        array_push($cate_ids,(int)$id);
        foreach (CategoryLogic::child($id) as $ch1){
            array_push($cate_ids,$ch1['id']);
            foreach (CategoryLogic::child($ch1['id']) as $ch2){
                array_push($cate_ids,$ch2['id']);
            }
        }

        $products = Product::active()->get();
        $product_ids = [];
        foreach ($products as $product) {
            foreach (json_decode($product['category_ids'], true) as $category) {
                if (in_array($category['id'],$cate_ids)) {
                    array_push($product_ids, $product['id']);
                }
            }
        }

        return Product::with('rating')->whereIn('id', $product_ids)->get();
    }
}
