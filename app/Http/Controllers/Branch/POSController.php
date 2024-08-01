<?php

namespace App\Http\Controllers\Branch;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Model\AddOn;
use App\Model\Admin;
use App\Model\AdminRole;
use App\Model\Branch;
use App\Model\Category;
use App\Model\CustomerAddress;
use App\Model\Notification;
use App\Model\Product;
use App\Model\Order;
use App\Model\OrderDetail;
use App\Model\Table;
use App\User;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use function App\CentralLogics\translate;

class POSController extends Controller
{
    public function customer_address_list(Request $request)
    {
        $customer_addresses = CustomerAddress::query()->where('user_id', $request->customer_id)->get();
        return response()->json([
            'addresses' => $customer_addresses
        ]);
    }

    public function customer_address(Request $request)
    {
        $customer_address = CustomerAddress::query()->where('id', $request->address_id)->first();
        return response()->json([
            'address' => $customer_address
        ]);
    }
    public function index(Request $request)
    {
        $category = $request->query('category_id', 0);
        $product_type = $request->query('product_type', 'all');
        $categories = Category::query()->active()->get();
        $first_category = Category::query()->active()->first();
        $keyword = $request->keyword;
        $key = explode(' ', $keyword);
        $selected_customer = User::query()->where('id', session('customer_id'))->first();
        $selected_table = Table::query()->where('id', session('table_id'))->first();

        $products = Product::query()
            ->when($product_type != 'all', function ($query) use ($product_type) {
                $query->where('product_type', $product_type);
            })
            ->when($request->has('category_id') && $request['category_id'] != 0, function ($query) use ($request) {
                $query->whereJsonContains('category_ids', [['id' => (string)$request['category_id']]]);
            })
            ->when($request->has('category_id') == 0, function ($query) use ($request,$first_category) {
                $query->whereJsonContains('category_ids', [['id' => (string)$first_category->id]]);
            })
            ->when($keyword, function ($query) use ($key) {
                return $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })
            ->active()->latest()->paginate(Helpers::getPagination());

        $branch = Branch::query()->find(auth('branch')->id());
        $tables = Table::query()->where(['branch_id' => auth('branch')->id()])->get();
        $pos_role = AdminRole::where('name', 'pos')->first();
        if ($pos_role) {
            $employees = Admin::where('admin_role_id', $pos_role->id)->select('id', 'f_name')->get();
        } else {
            $employees = [];
        }
        return view('branch-views.pos.index', compact('categories', 'products', 'category', 'keyword', 'branch', 'tables', 'selected_table', 'selected_customer', 'employees'));
    }

    public function quick_view(Request $request)
    {
        $product = Product::query()->findOrFail($request->product_id);



        return response()->json([
            'success' => 1,
            'view' => view('branch-views.pos._quick-view-data', compact('product'))->render(),
        ]);
    }

    public function variant_price(Request $request)
    {
        $product = Product::query()->find($request->id);
        $str = '';
        $quantity = $request->half_half ?? $request->quantity;
        $price = 0;
        $addon_price = 0;

        foreach (json_decode($product->choice_options) as $key => $choice) {
            if ($str != null) {
                $str .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $str .= str_replace(' ', '', $request[$choice->name]);
            }
        }

        if ($request['addon_id']) {
            foreach ($request['addon_id'] as $id) {
                $addon_price += $request['addon-price' . $id] * $request['addon-quantity' . $id];
            }
        }

        if ($str != null) {
            $count = count(json_decode($product->variations));
            for ($i = 0; $i < $count; $i++) {
                if (json_decode($product->variations)[$i]->type == $str) {
                    if ($request->half_half) {
                        $price = HALF_HALF_PRICE - Helpers::discount_calculate($product, HALF_HALF_PRICE);
                    } else {
                        $price = json_decode($product->variations)[$i]->price - Helpers::discount_calculate($product, $product->price);
                    }
                }
            }
        } else {
            if ($request->half_half) {
                $price = HALF_HALF_PRICE - Helpers::discount_calculate($product, HALF_HALF_PRICE);
            } else {
                $price = $product->price - Helpers::discount_calculate($product, $product->price);
            }
        }

        return array('price' => Helpers::set_symbol(($price * $quantity) + $addon_price));
    }

    public function get_customers(Request $request)
    {
        if ($request->customer_id) {
            $data = DB::table('users')
                ->where('id', $request->customer_id)
                ->get([DB::raw('id, CONCAT(f_name, " ", " (", phone ,")") as text')]);
        } else {
            $key = explode(' ', $request['q']);
            $data = DB::table('users')
                ->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        if (@$value[0] == 0) {
                            $value = substr($value, 1);
                        }
                        $q->orWhere('f_name', 'like', "%{$value}%")
//                            ->orWhere('l_name', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%");
                    }
                })
                ->whereNotNull(['f_name', 'phone'])
                ->limit(8)
                ->get([DB::raw('id, CONCAT(f_name, " ", " (", phone ,")") as text')]);
        }

        $q = $request['q'];

        if(count($data) == 0 && strlen($q) == 10 && is_numeric($q) && $q[0] == 0) {

            $user = User::create([
               'f_name' => 'New Customer',
                'phone' => '+61' . substr($q, 1),
            ]);

            $data[] = (object)['id' => $user->id, 'text' => $user->f_name . ' (' . $user->phone . ')'];
        }

        $data[] = (object)['id' => false, 'text' => translate('walk_in_customer')];

        return response()->json($data);
    }

    public function update_tax(Request $request)
    {
        if ($request->tax < 0) {
            Toastr::error(translate('Tax_can_not_be_less_than_0_percent'));
            return back();
        } elseif ($request->tax > 100) {
            Toastr::error(translate('Tax_can_not_be_more_than_100_percent'));
            return back();
        }

        $cart = $request->session()->get('cart', collect([]));
        $cart['tax'] = $request->tax;
        $request->session()->put('cart', $cart);
        return back();
    }

    public function update_discount(Request $request)
    {
        if ($request->type == 'percent' && $request->discount < 0) {
            Toastr::error(translate('Extra_discount_can_not_be_less_than_0_percent'));
            return back();
        } elseif ($request->type == 'percent' && $request->discount > 100) {
            Toastr::error(translate('Extra_discount_can_not_be_more_than_100_percent'));
            return back();
        }

        $cart = $request->session()->get('cart', collect([]));
        $cart['extra_discount_type'] = $request->type;
        $cart['extra_discount'] = $request->discount;

        $request->session()->put('cart', $cart);
        return back();
    }

    public function updateQuantity(Request $request)
    {
        $cart = $request->session()->get('cart', collect([]));
        $cart = $cart->map(function ($object, $key) use ($request) {
            if ($key == $request->key) {
                $object['quantity'] = $request->quantity;
            }
            return $object;
        });
        $request->session()->put('cart', $cart);
        return response()->json([], 200);
    }

    public function addToCart(Request $request)
    {
        $product = Product::query()->find($request->id);

        $data = array();
        $data['id'] = $product->id;
        $str = '';
        $variations = [];
        $price = 0;
        $addon_price = 0;
        $quantity = $request->half_half ?? $request->quantity;

        //Gets all the choice values of customer choice option and generate a string like Black-S-Cotton
        foreach (json_decode($product->choice_options) as $key => $choice) {
            $data[$choice->name] = $request[$choice->name];
            $variations[$choice->title] = $request[$choice->name];
            if ($str != null) {
                $str .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $str .= str_replace(' ', '', $request[$choice->name]);
            }
        }
        $data['variations'] = $variations;
        $data['variant'] = $str;
        if ($request->session()->has('cart')) {
            if (count($request->session()->get('cart')) > 0) {
                $totalQuantity = 0;
                foreach ($request->session()->get('cart') as $key => $cartItem) {
                    if (is_array($cartItem) && $cartItem['id'] == $request['id'] && $cartItem['variant'] == $str) {
                        return response()->json([
                            'data' => 1
                        ]);
                    }
                    $totalQuantity += $cartItem['quantity'];
                }

                if (fmod($totalQuantity, 1) !== 0.00 && $quantity != 0.5) {
                    return response()->json([
                        'data' => 2,
                    ]);
                }

            }
        }
        //Check the string and decreases quantity for the stock
        if ($str != null) {
            $count = count(json_decode($product->variations));
            for ($i = 0; $i < $count; $i++) {
                if (json_decode($product->variations)[$i]->type == $str) {
                    if ($request->half_half) {
                        $price = HALF_HALF_PRICE;
                    } else {
                        $price = json_decode($product->variations)[$i]->price;
                    }
                }
            }
        } else {
            if ($request->half_half) {
                $price = HALF_HALF_PRICE;
            } else {
                $price = $product->price;
            }
        }

        $data['quantity'] = $quantity;
        $data['price'] = $price;
        $data['name'] = $product->name;
        $data['discount'] = Helpers::discount_calculate($product, $price);
        $data['image'] = $product->image;
        $data['add_ons'] = [];
        $data['add_on_qtys'] = [];
        $data['allergies'] = [];

        if ($request['addon_id']) {
            foreach ($request['addon_id'] as $id) {
                $addon_price += $request['addon-price' . $id] * $request['addon-quantity' . $id];
                $data['add_on_qtys'][] = $request['addon-quantity' . $id];
            }
            $data['add_ons'] = $request['addon_id'];
        }

        $data['addon_price'] = $addon_price;

        if ($request['allergy_id']) {
            $data['allergies'] = $request['allergy_id'];
        }

        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            $cart->push($data);
        } else {
            $cart = collect([$data]);
            $request->session()->put('cart', $cart);
        }

        return response()->json([
            'data' => $data
        ]);
    }

    public function reorder($order_id)
    {
        $order = Order::query()->findOrFail($order_id);

        $cart = collect([]);
        session()->forget('cart');

        foreach ($order->details as $details) {


            $product = Product::query()->find($details->product_id);

            $data = array();
            $data['id'] = $product->id;
            $price = 0;
            $addon_price = 0;
            $variations = [];

            if ($details['variation']) {
                foreach (json_decode($details['variation']) as $index => $variation) {
                    $variations['Size'] = $variation->type;
                    $data['choice_'.($index+1)] = $variation->type;
                    $price += $variation->price;
                }
            } else {
                $price = $product->price;
            }

            $data['variations'] = $variations;
            $data['variant'] = json_decode($details['variant']);

            $data['quantity'] = $details['quantity'];
            $data['price'] = $price;
            $data['name'] = $product->name;
            $data['discount'] = Helpers::discount_calculate($product, $price);
            $data['image'] = $product->image;
            $data['add_ons'] = [];
            $data['add_on_qtys'] = [];
            $data['allergies'] = [];

            if ($details['add_on_ids']) {
                foreach (json_decode($details['add_on_ids']) as $index => $id) {
                    $addon_price += AddOn::find($id)->price * json_decode($details['add_on_qtys'])[$index];
                }
                $data['add_on_qtys'] = json_decode($details['add_on_qtys']);
                $data['add_ons'] = json_decode($details['add_on_ids']);
            }

            $data['addon_price'] = $addon_price;

            if ($details['allergy_ids']) {
                $data['allergies'] = json_decode($details['allergy_ids']);
            }

            $cart->push($data);
        }

        $delivery_address = null;

        if ($order->delivery_address_id) {
            $delivery_address = CustomerAddress::query()->find($order->delivery_address_id);
        }
        if(!$delivery_address && $order->delivery_address) {
            $delivery_address = json_decode(json_encode(str_replace('/','',$order->delivery_address)),true);
        }

        $delivery_address['delivery_charge'] = $order->delivery_charge;

        session()->put('customer_id', $order->user_id);
        session()->put('branch_id', $order->branch_id);

        session()->put('cart', $cart);

        if ($delivery_address) {
            return redirect()->back()->with('delivery_address', $delivery_address);
        }

        return redirect()->back();
    }

    public function cart_items()
    {
        return view('branch-views.pos._cart_render');
    }

    public function emptyCart(Request $request)
    {
        session()->forget('cart');
        return response()->json([], 200);
    }

    public function removeFromCart(Request $request)
    {
        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            $cart->forget($request->key);
            $request->session()->put('cart', $cart);
        }

        return response()->json([], 200);
    }


    //order
    public function order_list(Request $request)
    {
        $from = $request->from;
        $to = $request->to;
        $query_param = [];
        $search = $request['search'];

        Order::query()->where(['checked' => 0])->update(['checked' => 1]);
        $query = Order::query()->pos()->with(['customer', 'branch'])->where('branch_id', auth('branch')->id());

        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $query = $query->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('order_status', 'like', "%{$value}%")
                        ->orWhere('transaction_reference', 'like', "%{$value}%");
                }
            });
            $query_param = ['search' => $request['search']];
        }

        $orders = $query->latest()->paginate(Helpers::getPagination())->appends($query_param);

        return view('branch-views.pos.order.list', compact('orders','search', 'from', 'to'));
    }

    //order
    public function delivery_popup_orders(Request $request)
    {
        $from = $request->from ?? date('Y-m-d');
        $to = $request->to ?? date('Y-m-d');
        $query_param = [];
        $search = $request['search'];

        // Order::where(['checked' => 0])->update(['checked' => 1]);
        $query = Order::query()->pos()->with(['customer', 'branch'])->where('branch_id', auth('branch')->id());

        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $query = $query->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('order_status', 'like', "%{$value}%")
                        ->orWhere('transaction_reference', 'like', "%{$value}%")
                        ->orWhereHas('customer', function ($q) use ($value) {
                            $q->where('f_name', 'like', "%{$value}%")
                                ->orWhere('l_name', 'like', "%{$value}%")
                                ->orWhere('phone', 'like', "%{$value}%");
                        });
                }
            });
            $query_param = ['search' => $request['search']];
        }

//        if ($request->from || $request->to) {
//            if($request->from && !$request->to){
//                $query->whereBetween('created_at',[$request->from.' 00:00:00',date('Y-m-d').' 23:59:59']);
//                $query_param = ['from' => $request['from']];
//            } elseif (!$request->from && $request->to){
//                $query->whereBetween('created_at',['1970-01-01 00:00:00',$request->to.' 23:59:59']);
//                $query_param = ['to' => $request['to']];
//            } else {
//                $query->whereBetween('created_at',[$request->from.' 00:00:00',$request->to.' 23:59:59']);
//                $query_param = [
//                    'from' => $request['from'],
//                    'to' => $request['to'],
//                ];
//            }
//        }

        $query->whereBetween('created_at',[$from.' 00:00:00',$to.' 23:59:59']);
        $query_param = [
            'from' => $from,
            'to' => $to,
        ];

        $orders = $query->latest()/*->paginate(Helpers::getPagination())*/->take(200)->get();

        session()->put('order_data_export', $orders);

        return view('branch-views.pos.order.partials.delivery_menu_table', compact('orders','search', 'from', 'to'));
    }

    // accept order
    public function accept_order(Request $request)
    {
        $order = Order::query()->where(['id' => $request->id])->first();

        if ($order) {
            $order->checked = 1;
            $order->order_status = 'processing';
            $order->save();

            return true;
        } else {
            return false;
        }
    }

    // accept all orders
    public function accept_all_orders(Request $request)
    {
        $orders = Order::query()
            ->where('branch_id', auth('branch')->id())
            ->where('order_status', 'confirmed')
            ->where('checked', 0)
            ->get();

        if (count($orders) > 0) {
            foreach ($orders as $order){
                $order->checked = 1;
                $order->order_status = 'processing';
                $order->save();
            }

            return ['ids' => $orders->toQuery()->pluck('id')];
        } else {
            return ['ids' => []];
        }
    }

    public function order_details($id)
    {
        $order = Order::with('details')->where(['id' => $id, 'branch_id' => auth('branch')->id()])->first();
        if (isset($order)) {
            return view('branch-views.pos.order.order-view', compact('order'));
        } else {
            Toastr::info('No more orders!');
            return back();
        }
    }

    public function place_order(Request $request)
    {
        if (!$request->session()->has('order_taken_by')) {
            Toastr::error(translate('Please select your name'));
            return back();
        }

        if ($request->session()->has('cart')) {
            if (count($request->session()->get('cart')) < 1) {
                Toastr::error(translate('cart_empty_warning'));
                return back();
            }
        } else {
            Toastr::error(translate('cart_empty_warning'));
            return back();
        }
        if (session('people_number') != null && (session('people_number') > 99 || session('people_number') <1)){
            Toastr::error(translate('enter valid people number'));
            return back();
        }

        $cart = $request->session()->get('cart');
        $toalCount = 0;
        foreach ($cart as $item) {
            $toalCount += $item['quantity'];
        }
        if(fmod($toalCount, 1) !== 0.00){
            Toastr::error(translate('Please complete Half / Half order'));
            return back();
        }


        if ($request->get('receive_by') == 'delivery') {
            if (!$request->get('customer_address_id') && (!$request->get('address') || !$request->get('contact_person_name')
                || !$request->get('contact_person_number'))) {
                Toastr::error(translate('Please provide required delivery address information'));
                return back();
            }

            $address = [
                'contact_person_name' => $request->contact_person_name,
                'contact_person_number' => $request->contact_person_number,
                'floor' => $request->floor,
                'house' => $request->house,
                'road' => $request->road,
                'address_type' => $request->address_type ?? 'Other',
                'address' => $request->address,
                'longitude' => $request->longitude,
                'latitude' => $request->latitude,
            ];

            if ($request->get('customer_address_id')) {
                $customer_address = CustomerAddress::find($request->get('customer_address_id'));
                $customer_address->update($address);
            } else {
                $address['user_id'] = $request->user_id;
                $customer_address = CustomerAddress::create($address);
            }

        }

        $total_tax_amount = 0;
        $total_addon_price = 0;
        $product_price = 0;
        $order_details = [];

        // $order_id = 100000 + Order::all()->count() + 1;
        $order_id = (@Order::query()->latest()->first() ? @Order::query()->latest()->first()->id : 0) + 1;
        if (Order::query()->find($order_id)) {
            $order_id = Order::query()->orderBy('id', 'DESC')->first()->id + 1;
        }

        $order = new Order();
        $order->id = $order_id;

        $order->user_id = $request->user_id;
        $order->coupon_discount_title = $request->coupon_discount_title == 0 ? null : 'coupon_discount_title';
        $order->payment_status = $request->type == 'pay_after_eating' ? 'unpaid' : 'paid';

        if ($request->receive_by == 'delivery' && $request->type == 'cod') {
            $order->payment_status = 'unpaid';
        }

        if ($request->has('save')) {
            $order->payment_status = 'unpaid';
        }

        $order->order_status = session()->get('table_id') ? 'confirmed' : 'delivered';

        if ($request->receive_by == 'delivery') {
            if ($request->type == 'cod') {
                $order->order_status = 'pending';
            } else {
                $order->order_status = 'confirmed';
            }
        }

        if ($request->has('save')) {
            $order->order_status = 'pending';
        }

        $order->order_type = session()->get('table_id') ? 'dine_in' : 'pos';

        if ($request->receive_by == 'delivery') {
            $order->order_type = 'delivery';
        }

        $order->coupon_code = $request->coupon_code ?? null;
        $order->payment_method = $request->type;
        $order->transaction_reference = $request->transaction_reference ?? null;

        $order->delivery_charge = 0; //since pos, no distance, no d. charge
        $order->delivery_address_id = $request->delivery_address_id ?? null;
        $order->delivery_date = Carbon::now()->format('Y-m-d');
        $order->delivery_time = Carbon::now()->format('H:i:s');


        if ($request->receive_by == 'delivery') {
            $order->delivery_charge = Helpers::get_delivery_charge($request->distance);
            $order->delivery_address_id = @$customer_address->id ?? null;
            $order->preparation_time = Helpers::get_business_settings('default_preparation_time') ?? 0;
            $order->order_state = 'current';
            $order->delivery_address = isset($customer_address) ? json_encode($customer_address) : null;
        }

        $order->order_taken_by = $request->session()->get('order_taken_by');
        $order->order_note = null;
        $order->checked = 1;
        $order->created_at = now();
        $order->updated_at = now();

        $total_product_main_price = 0;

        // check if discount is more than total price
        $total_price_for_discount_validation = 0;

        foreach ($cart as $c) {
            if (is_array($c)) {
                $discount_on_product = 0;
                $product_subtotal = ($c['price']) * $c['quantity'];
                $discount_on_product += ($c['discount'] * $c['quantity']);

                $total_price_for_discount_validation += $c['price'];

                $product = Product::query()->find($c['id']);
                if ($product) {
                    $price = $c['price'];

                    $product = Helpers::product_data_formatting($product);
                    $addon_data = Helpers::calculate_addon_price(AddOn::query()->whereIn('id', $c['add_ons'])->get(), $c['add_on_qtys']);

                    //***bypass check for POS variation***
                    $result = [];
                    if(!empty($c['variations'])) {
                        foreach (gettype($product['variations']) == 'array' ? $product['variations'] : json_decode($product['variations'], true) as $key => $product_variation) {
                            //Here 'Size' is coupled with POS order's variation architecture, think before you change
                            if ($product_variation['type'] == current($c['variations']) || $product_variation['type'] == str_replace(" ","",current($c['variations']))) {
                                $result[] = [
                                    'type' => $product_variation['type'],
                                    'price' => Helpers::set_price($product_variation['price'])
                                ];
                            }
                        }
                    }

                    if(count($result) > 0) {
                        $encoded_variation = json_encode($result);
                    } else {
                        $encoded_variation = json_encode([]);
                    }
                    //***end***

                    //*** addon quantity integer casting ***
                    array_walk($c['add_on_qtys'], function (&$add_on_qtys) {
                        $add_on_qtys = (int) $add_on_qtys;
                    });
                    //***end***


                    $or_d = [
                        'product_id' => $c['id'],
                        'product_details' => $product,
                        'quantity' => $c['quantity'],
                        'price' => $price,
                        'tax_amount' => Helpers::tax_calculate($product, $price),
                        'discount_on_product' => Helpers::discount_calculate($product, $price),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => $encoded_variation,
                        'add_on_ids' => json_encode($addon_data['addons']),
                        'add_on_qtys' => json_encode($c['add_on_qtys']),
                        'allergy_ids' => json_encode($c['allergies']),
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $total_tax_amount += $or_d['tax_amount'] * $c['quantity'];
                    $total_addon_price += $addon_data['total_add_on_price'];
                    $product_price += $product_subtotal - $discount_on_product;
                    $total_product_main_price += $product_subtotal;
                    $order_details[] = $or_d;
                }
            }
        }

        $total_price = $product_price + $total_addon_price;
        if (isset($cart['extra_discount'])) {
            $extra_discount = $cart['extra_discount_type'] == 'percent' && $cart['extra_discount'] > 0 ? (($total_product_main_price * $cart['extra_discount']) / 100) : $cart['extra_discount'];
            $total_price -= $extra_discount;
        }
        if(isset($cart['extra_discount']) && $cart['extra_discount_type'] == 'amount') {
            if ($cart['extra_discount'] > $total_price_for_discount_validation) {
                Toastr::error(translate('discount_can_not_be_more_total_product_price'));
                return back();
            }
        }
        $tax = isset($cart['tax']) ? $cart['tax'] : 0;
        $total_tax_amount = ($tax > 0) ? (($total_price * $tax) / 100) : $total_tax_amount;
        try {
            $order->extra_discount = $extra_discount ?? 0;
            $order->total_tax_amount = $total_tax_amount;
            $order->order_amount = $total_price + $total_tax_amount + $order->delivery_charge;

            $order->coupon_discount_amount = 0.00;
            $order->branch_id = auth('branch')->id();
            $order->table_id = session()->get('table_id');
            $order->number_of_people = session()->get('people_number');

            $order->save();

            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }

            OrderDetail::insert($order_details);

            session()->forget('cart');
            session()->forget('customer_id');
            session()->forget('branch_id');
            session()->forget('table_id');
            session()->forget('people_number');

            if ($request->has('save')) {
                session(['last_order' => false]);
                Toastr::success(translate('order_saved_successfully'));
            } else {
                session(['last_order' => $order->id]);
                Toastr::success(translate('order_placed_successfully'));

//                $user = User::query()->find($order->user_id);
//                $fcm_token = $user->cm_firebase_token;
//                $value = Helpers::order_status_update_message(($request->payment_method=='cash_on_delivery')?'pending':'confirmed');
//                try {
//                    //send push notification
//                    if ($value) {
//                        $data = [
//                            'title' => translate('Order'),
//                            'description' => $value,
//                            'order_id' => $order_id,
//                            'image' => '',
//                            'type'=>'order_status',
//                        ];
//                        Helpers::send_push_notif_to_device($fcm_token, $data);
//                    }
//
//                    //send email
//                    $emailServices = Helpers::get_business_settings('mail_config');
//                    if (isset($emailServices['status']) && $emailServices['status'] == 1) {
//                        Mail::to($user->email)->send(new \App\Mail\OrderPlaced($order_id));
//                    }
//
//                } catch (\Exception $e) {
//
//                }

                //send notification to kitchen
//                if ($order->order_type == 'dine_in' || $order->order_type == 'delivery'){
//                    $notification = new Notification;
//                    $notification->title =  "You have a new order from POS - (Order Confirmed). ";
//                    $notification->description = $order->id;
//                    $notification->status = 1;
//
//                    try {
//                        Helpers::send_push_notif_to_topic($notification, "kitchen-{$order->branch_id}",'general');
//                        Toastr::success(translate('Notification sent successfully!'));
//                    } catch (\Exception $e) {
//                        Toastr::warning(translate('Push notification failed!'));
//                    }
//                }
            }

            return back();

        } catch (\Exception $e) {
            info($e);
        }
        Toastr::warning(translate('failed_to_place_order'));
        return back();
    }

    public function generate_invoice($id)
    {
        $order = Order::query()->where('id', $id)->first();

        return response()->json([
            'success' => 1,
            'view' => view('branch-views.pos.order.invoice', compact('order'))->render(),
        ]);
    }

    public function generate_multi_invoice(Request $request)
    {
        $orders = Order::query()->whereIn('id', $request->order_ids ?? [])->get();

        return response()->json([
            'success' => 1,
            'view' => view('branch-views.pos.order.invoice_multiple', compact('orders'))->render(),
        ]);
    }

    public function clear_session_data()
    {
        session()->forget('customer_id');
        session()->forget('table_id');
        session()->forget('people_number');
        Toastr::success(translate('clear data successfully'));
        return back();
    }

    public function customer_store(Request $request)
    {
        $request->validate([
            'f_name' => 'required',
//            'l_name' => 'required',
            'phone' => 'required|min:10|max:10|regex:/^0\d{9}$/|unique:users',
            'email' => 'nullable|email|unique:users',

        ]);

        $phone = $request->phone;

        if(strlen($phone) == 10 && is_numeric($phone) && $phone[0] == 0) {
            $phone = '+61' . substr($phone, 1);
            $user = User::query()->create([
                'f_name' => $request->f_name,
                'l_name' => $request->l_name,
                'email' => $request->email,
                'phone' => $phone,
                'password' => bcrypt($phone),
            ]);
            Toastr::success(translate('customer added successfully'));
            return back();
        }
    }

    public function store_keys(Request $request)
    {
        session()->put($request['key'], $request['value']);
        return response()->json($request['key'], 200);
    }

}
