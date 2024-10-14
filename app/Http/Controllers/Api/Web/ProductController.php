<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        // get product
        $products = Product::with('category')
        // count and average
        ->withAvg('reviews', 'rating')
        ->withCount('reviews')
        // search
        ->when(request()->q, function($products) {
            $products = $products->where('title', 'like', '%'. request()->q . '%');
        })->latest()->paginate(8);

        // return with api resource
        return new ProductResource(true, 'List Data Products', $products);
    }


    public function show($slug) {
        $product = Product::with('category', 'reviews.customer')
        // count and average 
        ->withAvg('reviews', 'rating')
        ->withCount('reviews')
        ->where('slug', $slug)->first();

        if ($product) {
            // return success with api resource
            return new ProductResource(true, 'Detail Data Product', $product);
        }

        // return failed with api resource
        return new ProductResource(false, 'Detail Product Tidak Ditemukan', null);
    }
}
