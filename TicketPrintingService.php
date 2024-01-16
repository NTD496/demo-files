<?php

namespace CTReporting\Services;

use Illuminate\Support\Facades\Auth;
use CTReporting\Libraries\Vend\VendAPIIntegration;
use CTReporting\Services\AppService;
use CTReporting\TicketUnit;
use CTReporting\TicketPrinting;
use CTReporting\CoreRangeProduct;
use CTReporting\CoreRangeProductUpdate;
use CTReporting\VendProduct;
use CTReporting\Store;
use Session;
use Illuminate\Support\Str;
use Milon\Barcode\DNS1D;
use Illuminate\Support\Facades\DB;
use CTReporting\PriceUpdateImportHistory;
use CTReporting\PriceUpdateImportPrintTicket;

class TicketPrintingService
{
    
    public function __construct()
    {
        $this->appService = new AppService();
    }
    
    /**
     * Get the price per quantity
     *
     * @param Array $product
     * @return Array
     */
    public function getPricePerQuantity($product)
    {
        $filter_product = [];
        
        $product_name = ((isset($product['variant_name'])) && (!empty($product['variant_name']))) ? $product['variant_name'] : $product['name'];
        /*Regex to remove any value inside bracket '()' to exclude it from calculation*/
        $product_name = preg_replace("/\([^)]+\)/","",$product_name);
        $product_name = str_replace("x"," x ",strtolower($product_name));

        $textBeforeX = substr($product_name, 0, strpos(strtolower($product_name), 'x'));
        /*Get all numbers from string*/
        preg_match_all('/\d+/', $textBeforeX, $numbers);
        /*Get last value from array for quantity*/
        $qty = end($numbers[0]);
        if($qty == 0) {
            $qty = 1;
        }

        // create array of product name
        $explode_product_name = explode(" ", $product_name);
        $explode_product_name = array_values(array_filter($explode_product_name));

        // pluck symbol from unit in database and create an array 
        $symbols = TicketUnit::pluck('symbol')->toArray();
        foreach ($explode_product_name as $each_product_word) {
            $name = strtolower($each_product_word);
            foreach ($symbols as $symbol) {
                if (strpos($name, $symbol) !== FALSE) { 

                    // separate quantity value from name
                    $quantity_value = preg_split('/(?<=[0-9])(?=[a-z]+)/i',$name);

                    // seperate unit symbol from name
                    $unit_symbol = preg_replace('/[^a-z]+/', '',$name);
                    $unit_symbol = str_replace("x","",strtolower($unit_symbol));
                    $quantity = preg_replace('/[^0-9\.,]+/', '',$quantity_value[0]);
                    if (!Str::contains($name, '/')) {
                        if ($unit_symbol && $quantity) {
                            $ticket_unit = TicketUnit::where('symbol','=',$unit_symbol)->first();
                            if (is_numeric($quantity) && !empty($ticket_unit)) {

                                //calculate price per unit
                                $pricePerQty = ($product['price_including_tax']/$quantity) * $ticket_unit->divide_by;
                                $qtyPrice = $pricePerQty / $qty;
                                $quantity_price = $quantity/$ticket_unit->divide_by;
                                $price = $product['price_including_tax']/$quantity_price;
                                $filter_product['quantity_unit']  = $ticket_unit->unit;
                                $filter_product['quantity_price'] = number_format($qtyPrice,2);
                                $filter_product['unit_price']     = $quantity_price;
                            }
                        }
                    } else {
                        $filter_product['quantity_unit']  = $unit_symbol;
                        $filter_product['quantity_price'] = number_format($product['price_including_tax'],2);
                        $filter_product['unit_price']     = '';
                    }
                }
            }
        }
        return $filter_product; 
    }

    /**  Query to get the flag product
    * 
    * @return Array
    */
    public function getProductFlagQuery(){
        $product_flags = CoreRangeProduct::withTrashed()->selectRaw('sku, CASE 
            WHEN deleted_at IS NOT NULL THEN 4 
            WHEN is_core_must_stock = 1 THEN 1 
            ELSE 2 
            END AS flag');

        return ['product_flags' => $product_flags];
    }

    
    /**
    * Process SKU result set obtained by csv file or for scanned SKUs(via App) and fetch the products for printing
    * If SKU result set is obtained by csv file, then also import the same to DB  
    * 
    * @param Array $sku_result (Array of SKUs returned from the uploaded file or scanned via App)
    * @param Array $data (Array of request parameters)
    *
    * @return Array
    */
    public function fetchAndImportProductsFromSkuResult($sku_result,$data) 
    {
        $store   = Store::findOrFail($data['store_id']);
        $store_ids = \Auth::user()->getVisibleStores()->where('is_product_mgmt_enabled', 1)->pluck('id')->toArray() ?? [];
            
        // get currency symbol 
        $currency_symbol = $this->appService->getOutletCurrencySymbol($store);

        // Get the flag when ticket are printed from import, scanned products
        if(($data['show_core_product'] == 1) && isset($sku_result['sku']) && (in_array($store->id,$store_ids))) {
            $product_flags = $this->getProductFlagQuery()['product_flags']->whereIn('sku',$sku_result['sku'])->pluck('flag','sku')->toArray();
        }

        // set session for ticket size
        Session::put('ticket_design', $data['ticket_design']);

        // set session for printer type
        Session::put('printer_type', $data['printer_type']);
        
        // Selected Store id put in session
        Session::put('selected_store_id', $data['store_id']);

        // set session for hide settings
        Session::put('hide_unit', $data['hide_unit']);
        Session::put('hide_price', $data['hide_price']);
        Session::put('hide_sku', $data['hide_sku']);
        Session::put('hide_barcode', $data['hide_barcode']);
        Session::put('show_core_product', $data['show_core_product']);
        Session::put('out_of_stock', $data['stock']);
        Session::put('print_all', $data['print_all']);
        $print_products = $multi_products = $product_result = $ticket_data = $products = [];

        if(isset($data['is_core_product']) && $data['is_core_product'] == 1){ 
            if(count($sku_result) == 0) return false;  
            $products = CoreRangeProductUpdate::leftJoin('core_range_products', 'core_range_products.sku', '=', 'core_range_product_updates.sku')
            ->select('core_range_product_updates.description as variant_name', 'core_range_product_updates.sku', 'core_range_products.sku as core_sku',
                     DB::raw('CASE WHEN core_range_products.deleted_at IS NOT NULL THEN 4 
                             WHEN core_range_products.is_core_must_stock = 1 THEN 1 
                             ELSE 2
                             END AS flag'),
                     DB::raw('CASE WHEN core_range_product_updates.new_retail_price = 0 
                              THEN core_range_product_updates.old_retail_price 
                              ELSE core_range_product_updates.new_retail_price 
                              END as price_including_tax'))
            ->whereIn('core_range_product_updates.sku', $sku_result)->get()->toArray(); 

            $sku_result = array('sku'=>$sku_result);
        }else if (isset($data['is_price_import_product']) && $data['is_price_import_product'] == 1) {
            if (count($sku_result) == 0) {
                return false;
            }

             $price_update_import_store = PriceUpdateImportPrintTicket::where('user_id', Auth::User()->id)->get();

            $price_update_import_product = PriceUpdateImportHistory::whereIn('price_update_import_history.sku', $sku_result)->whereIn('applied_date', $price_update_import_store->pluck('ticket_date')->toArray())->whereIn('store_id', $price_update_import_store->pluck('store_id')->toArray())->get();
           
            $vend_products = VendProduct::rightJoin('product_inventory', function ($inventory_join) use ($store_ids) {
                $inventory_join->on('vend_products.vend_id', '=', 'product_inventory.product_id')->whereIn('product_inventory.store_id', $store_ids);
            })->whereIn('vend_products.store_id', $store_ids)->whereIn('sku', $sku_result)->pluck('product_inventory.inventory_level', 'sku')->toArray();
            foreach ($price_update_import_product as $price_product_update) {
                if ((($vend_products[$price_product_update->sku] ?? 0) > 0) && !isset($data['show_positive_inventory'])) {
                    //Print products with positive stock value
                    $products[] = [
                        'variant_name' => $price_product_update->product_name,
                        'price_including_tax' => ($price_product_update->new_price == 0) ? number_format((float) $price_product_update->old_price, 2) : number_format((float) $price_product_update->new_price, 2),
                        'sku' => $price_product_update->sku,
                    ];
                } elseif (isset($data['show_positive_inventory'])) {
                    //Print all products
                    $products[] = [
                        'variant_name' => $price_product_update->product_name,
                        'price_including_tax' => ($price_product_update->new_price == 0) ? number_format((float) $price_product_update->old_price, 2) : number_format((float) $price_product_update->new_price, 2),
                        'sku' => $price_product_update->sku,
                    ];
                }
            }

            if (count($products) == 0) {
                return false;
            }

            $sku_result = array('sku' => $sku_result);

        }else{
            $vend_api = new VendAPIIntegration($store->domain_prefix ?? '', $store->personal_token ?? '');

            $products = $vend_api->searchProducts(array_map('strtolower', $sku_result['sku']));
            
            if(((is_bool($products)) && ($products == false)) || (empty($products))){
                return false;
            }
        }
        
        // Here the array_column function allows us to re-key the Vend Product data on the Vend SKU for easier lookup when displaying the information in the view
        $keyed_products = array_column($products, null, 'sku');

        // delete all tickets of the login user for the selected store from DB
        TicketPrinting::where('user_id',\Auth::user()->id)->where('store_id',$data['store_id'])->delete();
        
        //$sku_result used to print the barcodes in the same order in which they are scanned  
        foreach($sku_result['sku'] as $sku){
            if(isset($keyed_products[strtolower($sku)])){
                $print_product['name']  = $keyed_products[strtolower($sku)]['variant_name'];   
                $print_product['price'] = number_format((float)$keyed_products[strtolower($sku)]['price_including_tax'],2);  
                $print_product['sku']   = $keyed_products[strtolower($sku)]['sku'];
                $print_product['quantity_price'] = $print_product['quantity_unit']= '';
                $print_product['show_unit_price_flag']  =  0;
                // if hide unit is 1 then price,quantity unit ,quantity price and unit price will hide in ticket
                if($data['hide_price'] == 0) {
                // if hide unit is 1 then quantity unit ,quantity price and unit price will hide in ticket
                    if($data['hide_unit'] == 0){
                        // get price per quantity
                            $productValue = $this->getPricePerQuantity($keyed_products[strtolower($sku)]);
                        if(!empty($productValue)){
                            $print_product['quantity_unit']  = $productValue['quantity_unit'];
                            $print_product['quantity_price'] = $productValue['quantity_price'];   
                        }
                        $print_product['show_unit_price_flag']  =  1;
                    }
                    $print_product['show_price_flag']  =  1;
                } else {
                    $print_product['show_price_flag']  =  0;
                }

                if($data['stock'] == 0) {
                    $print_product['out_of_stock_flag']  =  0;
                }else{
                    $print_product['out_of_stock_flag']  =  1;
                }

                if($data['hide_sku'] == 0) {
                    $print_product['show_sku_flag']  =  1;
                }else{
                    $print_product['show_sku_flag']  =  0;
                }

                if($data['hide_barcode'] == 0) {
                    $print_product['show_barcode_flag']  =  1;
                }else{
                    $print_product['show_barcode_flag']  =  0;
                }

                if($data['hide_name_code'] == 0) {
                    $print_product['show_name_code']  =  1;
                }else{
                    $print_product['show_name_code']  =  0;
                }

                if($data['hide_currency'] == 0){
                    $print_product['show_currency'] = 1;
                }else{
                    $print_product['show_currency'] = 0;
                }

                // 1-Must Stock, 2-Core Optional, 3-sku not found in core range products, 4-Discontinued
                if((isset($data['is_core_product']) && ($data['is_core_product'] == 1))){
                    $print_product['show_core_product_flag'] = (!empty($keyed_products[strtolower($sku)]['core_sku'])) ? $keyed_products[strtolower($sku)]['flag'] : 3;
                }else if(($data['show_core_product'] == 1) && (count($store_ids) > 0)) {
                    $print_product['show_core_product_flag']  =  isset($product_flags[$sku]) ? $product_flags[$sku] : 3;
                }else{
                    $print_product['show_core_product_flag']  =  0;
                }

                $dns1d = new DNS1D;
                $print_product['barcodeImage'] = $dns1d->getBarcodeSVG($print_product['sku'], "C128");
                $print_product['currency'] = $currency_symbol;
                $print_product['size'] = $data['ticket_design'];
                //if printer is receipt printer
                if(($data['printer_type'] == "on") && ($data['print_all'] == 1)){

                    //If we are processing the Scanned SKUs resultset
                    if((isset($data['scanned_skus_flag'])) && ($data['scanned_skus_flag'] == true)){
                        array_push($print_products, $print_product);
                    }
                    else{
                        $product = [
                            'name'           => $print_product['name'],
                            'price'          => $print_product['price'],
                            'sku'            => $print_product['sku'],
                            'quantity_price' => $print_product['quantity_price'],
                            'quantity_unit'  => $print_product['quantity_unit'],
                            'size'           => $data['ticket_design'],
                            'currency'       => $currency_symbol,
                            'user_id'        => \Auth::user()->id,
                            'store_id'      => $data['store_id'],
                            'show_price_flag'     => $print_product['show_price_flag'],
                            'show_sku_flag'     => $print_product['show_sku_flag'],
                            'show_barcode_flag'     => $print_product['show_barcode_flag'],
                            'out_of_stock_flag'    => $print_product['out_of_stock_flag'],
                            'show_unit_price_flag' => $print_product['show_unit_price_flag'],
                            'show_core_product_flag' => $print_product['show_core_product_flag'],
                            'show_name_code' => $print_product['show_name_code'],
                            'show_currency' => $print_product['show_currency']
                        ];
                        //The create method was returning the object previously, so the product array is explicitly converted to object.
                        $ticket_data[] = $product;
                        array_push($print_products, (object)$product);
                    }
                }
                array_push($multi_products, $print_product);
            }
        }
        // Save product in database for the csv SKU resultset(if Receipt Printer)
        if(count($ticket_data) > 0){
            $ticket_data_collection = collect($ticket_data);
            // it will chunk the dataset in smaller collections containing 250 values each. 
            $ticket_data_chunks = $ticket_data_collection->chunk(250);
            foreach ($ticket_data_chunks as $chunk){
                TicketPrinting::insert($chunk->toArray());
            }
        }

        // If Receipt Printer, then we need to display the listing of all products & then printing it one by one, So we save each product in DB & push the record to $print_products array
        // If Label Printer, then we need to print all the multiple products in one go, So we don't save them in DB & push them directly to $multi_products array
            
        if(count($print_products)>0) {
            $product_result['print_products'] = $print_products;
        }
        $product_result['multi_products'] = $multi_products;
        $product_result['store'] = $store;
        return $product_result;      
    }

}
