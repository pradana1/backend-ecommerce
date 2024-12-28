<?php

namespace App\Http\Controllers\Api\Web;

use Midtrans\Snap;
use App\Models\Cart;
use App\Models\Invoice;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Resources\CheckoutResource;

class CheckoutController extends Controller
{
    /**
     * __construct
     */
    public function __construct()
    {
        // Set middleware
        $this->middleware('auth:api_customer');

        // Set midtrans configuration
        \Midtrans\Config::$serverKey    = config('services.midtrans.serverKey');
        \Midtrans\Config::$isProduction = config('services.midtrans.isProduction');
        \Midtrans\Config::$isSanitized  = config('services.midtrans.isSanitized');
        \Midtrans\Config::$is3ds        = config('services.midtrans.is3ds');
    }

    /**
     * store
     */
    public function store(Request $request)
    {
        // Validasi input
        $request->validate([
            'courier'          => 'required|string|max:255',
            'courier_service'  => 'required|string|max:255',
            'courier_cost'     => 'required|numeric',
            'weight'           => 'required|numeric',
            'name'             => 'required|string|max:255',
            'phone'            => 'required|string|max:15',
            'city_id'          => 'required|integer',
            'province_id'      => 'required|integer',
            'address'          => 'required|string|max:500',
            'grand_total'      => 'required|numeric',
        ]);

        // Jalankan transaksi database
        return DB::transaction(function () use ($request) {
            try {
                // Generate nomor invoice
                $no_invoice = 'INV-' . strtoupper(Str::random(10));

                // Simpan data invoice
                $invoice = Invoice::create([
                    'invoice'           => $no_invoice,
                    'customer_id'       => auth()->guard('api_customer')->user()->id,
                    'courier'           => $request->courier,
                    'courier_service'   => $request->courier_service,
                    'courier_cost'      => $request->courier_cost,
                    'weight'            => $request->weight,
                    'name'              => $request->name,
                    'phone'             => $request->phone,
                    'city_id'           => $request->city_id,
                    'province_id'       => $request->province_id,
                    'address'           => $request->address,
                    'grand_total'       => $request->grand_total,
                    'status'            => 'pending',
                ]);

                // Pindahkan data dari keranjang ke tabel orders
                $carts = Cart::where('customer_id', auth()->guard('api_customer')->user()->id)->get();
                foreach ($carts as $cart) {
                    $invoice->orders()->create([
                        'product_id' => $cart->product_id,
                        'qty'        => $cart->qty,
                        'price'      => $cart->price,
                    ]);
                }

                // Hapus keranjang pelanggan
                Cart::where('customer_id', auth()->guard('api_customer')->user()->id)->delete();

                // Buat payload untuk Midtrans
                $payload = [
                    'transaction_details' => [
                        'order_id'     => $invoice->invoice,
                        'gross_amount' => $invoice->grand_total,
                    ],
                    'customer_details' => [
                        'first_name'       => $invoice->name,
                        'email'            => auth()->guard('api_customer')->user()->email,
                        'phone'            => $invoice->phone,
                        'shipping_address' => $invoice->address,
                    ],
                ];

                // Buat Snap Token Midtrans
                $snapToken = Snap::getSnapToken($payload);

                if (!$snapToken) {
                    throw new \Exception('Failed to generate Snap Token.');
                }

                // Simpan Snap Token ke invoice
                $invoice->snap_token = $snapToken;
                $invoice->save();

                // Response sukses
                return new CheckoutResource(true, 'Checkout Successfully', [
                    'snap_token' => $snapToken,
                ]);

            } catch (\Exception $e) {
                // Tangani error jika ada
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }
        });
    }
}
