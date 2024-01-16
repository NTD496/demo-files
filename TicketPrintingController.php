<?php

namespace CTReporting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use CTReporting\Libraries\Vend\VendAPIIntegration;
use CTReporting\TicketUnit;
use CTReporting\TicketPrinting;
use CTReporting\ScannedProduct;
use CTReporting\CoreRangeProductUpdate;
use CTReporting\CoreRangeProductTicketPrintingSku;
use CTReporting\Store;
use Validator;
use Milon\Barcode\DNS1D;
use CTReporting\Libraries\TicketPrintingProcessor;
use Session;
use CTReporting\Services\AppService;
use CTReporting\Services\TicketPrintingService;
use CTReporting\PriceUpdateImportPrintTicket;

class TicketPrintingController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware(['auth', 'profile']);
        $this->appService = new AppService();
        $this->ticketPrintingService = new TicketPrintingService();
    }

    /**
     * Index - Ticket Printing
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $stores = Auth::user()->getVisibleStores();
        $users_product_mgmt_enabled_stores_count = Auth::user()->getVisibleStores()->where('is_product_mgmt_enabled',1)->count();
        $user = Auth::user();
        $selected_ticket_options = [];
        if($user->selected_ticket_options !== null)
            $selected_ticket_options = json_decode($user->selected_ticket_options);
        return view('ticket_printing.ticket', compact('stores','users_product_mgmt_enabled_stores_count', 'selected_ticket_options'));
    }

    /**
     * Get the product by sku
     *
     * @param  Store $store
     * @param  Boolean $hide_unit Hide Quantity Price 
     * @param  Boolean $stock Out of Stock
     * @param  String $sku Search Sku
     * @param  Boolean $hide_price Hide Price and qauntity price 
     * @param  Boolean $hide_sku Hide SKU 
     * @param  Boolean $hide_barcode Hide Barcode 
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProductBySku($store,$hide_unit,$stock,$sku,$hide_price,$hide_sku,$hide_barcode)
    {
        if (!Auth::user()->hasPermission('view_ticket_printing')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        
        $request = new Request([
            'store_id' => $store,
            'hide_unit' => $hide_unit,
            'stock' => $stock,
            'sku' => $sku,
            'hide_price' => $hide_price,
            'hide_sku' => $hide_sku,
            'hide_barcode' => $hide_barcode
        ]);

        $validator = Validator::make($request->all(), [
            'store_id'      => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
            'hide_unit'    => 'required|integer|in:0,1',
            'stock' => 'required|integer|in:0,1',
            'sku' => 'required|max:255|alpha_dash',
            'hide_price' => 'required|integer|in:0,1',
            'hide_sku' => 'required|integer|in:0,1',
            'hide_barcode' => 'required|integer|in:0,1'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => 'false', 'error' => $validator->messages()]);
        }
        
        $show_price_flag = $out_of_stock_flag = $show_sku_flag = $show_barcode_flag = $show_unit_price_flag = 0;
        $store = Store::findOrFail($store);
        
        // Get the first store - TODO: refresh this on store select (from cache?)
        $vend_api = new VendAPIIntegration($store->domain_prefix, $store->personal_token);
        $products = $vend_api->searchProducts([strtolower($sku)]);

        if (!empty($products)) {
            
            //get currency symbol
            $currency_symbol = $this->appService->getOutletCurrencySymbol($store);
            $quantity_price  = $quantity_unit = $unit_price = '';

            //if $hidePrice is 1 then price, quantity unit ,quantity price and unit price will hide in ticket
            if($hide_price == 0) {
                // if $hideUnit is 1 then quantity unit ,quantity price and unit price will hide in ticket
                if($hide_unit == 0) {

                    // get price per quantity
                    $productValue = $this->ticketPrintingService->getPricePerQuantity($products[0]);
                    if(!empty($productValue)){
                        $quantity_unit = $productValue['quantity_unit'];
                        $quantity_price = $productValue['quantity_price'];
                        $unit_price = $productValue['unit_price'];
                    }
                    $show_unit_price_flag = 1;
                }
                $price = number_format((float)$products[0]['price_including_tax'],2);
                $show_price_flag = 1;
            } else {
                $price = '';
            }
            
            // Set stock flag
            if($stock==1){
                $out_of_stock_flag = 1;
            }
            
            if($hide_sku == 0) {
                $show_sku_flag = 1;
            } 
            
            if($hide_barcode == 0) {
                $show_barcode_flag = 1;
            }
            $product = [
                'name'            => $products[0]['variant_name'],
                'price'           => $price,
                'currency_symbol' => $currency_symbol,
                'quantity_unit'   => $quantity_unit,
                'quantity_price'  => $quantity_price,
                'sku'             => $products[0]['sku'],
                'unitPrice'       => $unit_price,
                'out_of_stock_flag'=> $out_of_stock_flag,
                'show_price_flag' => $show_price_flag,
                'show_sku_flag' => $show_sku_flag,
                'show_barcode_flag' => $show_barcode_flag,
                'show_unit_price_flag' => $show_unit_price_flag,
            ];

            return response()->json(['success' => 'true','product' => $product]);
        } else {
            return response()->json(['success' => 'false']);
        }
    }

    /**
     * create ticket for printing import.
     * 
     * @param Request $request
     *
     * @return \Illuminate\View\View
     */
    public function createTicket(Request $request)
    {
        if (!Auth::user()->hasPermission('view_ticket_printing')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        $this->validate($request,[
            'store_id'      => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
            'product_sku'    => 'required|max:255|alpha_dash',
            'ticket_design' => 'required|string|in:normalticket,smallticket',
            'hide_unit'     => 'boolean|nullable',
            'hide_price'     => 'boolean|nullable',
            'hide_sku'     => 'boolean|nullable',
            'hide_barcode'     => 'boolean|nullable',
            'hide_name_code'     => 'boolean|nullable',
            'hide_currency' => 'boolean|nullable',
        ]);
        
        $store = Store::findOrFail($request->input('store_id'));

        $store_ids = \Auth::user()->getVisibleStores()->where('is_product_mgmt_enabled', 1)->pluck('id')->toArray();
        $core_product = $this->ticketPrintingService->getProductFlagQuery()['product_flags']->where('sku',$request->input('product_sku'))->first();
        $vend_api = new VendAPIIntegration($store->domain_prefix, $store->personal_token);
        
        //get currency symbol
        $currency_symbol = $this->appService->getOutletCurrencySymbol($store);
        $search_sku = strtolower($request->input('product_sku'));
        $product = $vend_api->searchProducts([$search_sku]);
        $print_products = [];

        //if count of product is not equal to 1 then return back with error
        if(!$product) {
            $request->flash();
            return back()->with(['flashType' => 'danger', 'flashMessage' => 'Error! - there was no result for this SKU']);
        }else if(count($product) <> 1){
            $request->flash();
            return back()->with(['flashType' => 'danger', 'flashMessage' => 'Error! - there were more than one result for this SKU']);
        }
        else { 
            //check first product sku is equal to the search sku 
            if (strtolower($product[0]['sku']) == $search_sku) {   
                
                //get product detail in an array
                $print_product['name']  = $product[0]['variant_name'];   
                $print_product['price'] = number_format((float)$product[0]['price_including_tax'],2);  
                $print_product['sku']   = $product[0]['sku'];
                $print_product['quantity_price'] = $print_product['quantity_unit'] = '';
                $print_product['show_unit_price_flag'] = 0;
                $print_product['show_core_product_flag'] = 0;

                if(!$request->hide_price){
                    if(!$request->hide_unit){
                        
                        // get price per quantity
                        $productValue = $this->ticketPrintingService->getPricePerQuantity($product[0]);
                        if(!empty($productValue)){
                            $print_product['quantity_unit']  = $productValue['quantity_unit'];
                            $print_product['quantity_price'] = $productValue['quantity_price'];
                        }
                        $print_product['show_unit_price_flag'] = 1;
                        if((!empty($core_product) && in_array($store->id,$store_ids)) && $request->show_core_product){ 
                            $print_product['show_core_product_flag'] = $core_product->flag; 
                        }else{
                            $print_product['show_core_product_flag'] = 0;
                        }
                    }
                    $print_product['show_price_flag'] = 1;
                } else {
                    $print_product['show_price_flag'] = 0;
                }
                
                if(!$request->hide_sku){
                    $print_product['show_sku_flag'] = 1;
                }else{
                    $print_product['show_sku_flag'] = 0;
                }
                
                if(!$request->hide_barcode){
                    $print_product['show_barcode_flag'] = 1;
                }else{
                    $print_product['show_barcode_flag'] = 0;
                }

                if(!$request->hide_name_code){
                    $print_product['show_name_code'] = 1;
                }else{
                    $print_product['show_name_code'] = 0;
                }

                if(!$request->hide_currency){
                    $print_product['show_currency'] = 1;
                }else{
                    $print_product['show_currency'] = 0;
                }
            }else{
                $request->flash();
                return back()->with(['flashType' => 'danger', 'flashMessage' => 'Error! - there was no result for this SKU']);
            }     
        }
        
        //this will give you the correct view which will have the correct layout for the ticket requested
        $view = 'ticket_printing.'.$request->input('ticket_design');

        //if user change product name
        if($request->input('product_name')) { 
            $print_product['name'] = $request->input('product_name'); 
        } 

        //if user change product price
        if($request->input('product_price')) { 
            $print_product['price'] = number_format((float)$request->input('product_price'),2);
            $product['name'] = $product[0]['variant_name'];
            $product['price_including_tax'] = $request->input('product_price'); 
            if(!$request->hide_unit){
                $productValue = $this->ticketPrintingService->getPricePerQuantity($product);
                if(!empty($productValue)){
                    $print_product['quantity_unit']  = $productValue['quantity_unit'];
                    $print_product['quantity_price'] = $productValue['quantity_price'];
                }
            }
        }

        // Set stock flag
        if($request->stock) {
           $print_product['out_of_stock_flag']  = 1;
        } else {
           $print_product['out_of_stock_flag']  = 0;
        }

        $dnsid = new DNS1D;
        $print_product['barcodeImage']  = $dnsid->getBarcodeSVG($print_product['sku'], "C128",1,30);
        $print_product['currency'] = $currency_symbol;
        array_push($print_products, $print_product);
        //get log in user instance
        $user = Auth::user();

        $selected_ticket_options = [];
        if($request->input('hide_unit'))
            $selected_ticket_options[] = 'hide_unit';
        if($request->input('hide_price'))
            $selected_ticket_options[] = 'hide_price';
        if($request->input('hide_sku'))
            $selected_ticket_options[] = 'hide_sku';
        if($request->input('hide_barcode'))
            $selected_ticket_options[] = 'hide_barcode';
        if($request->input('hide_name_code')) {
            $selected_ticket_options[] = 'hide_name_code';
        }else{
            $selected_ticket_options[] = 'show_name_code';
        }
        if($request->input('hide_currency')){
            $selected_ticket_options[] = 'hide_currency'; 
        }

        $selected_ticket_optionsJson = json_encode($selected_ticket_options); 
        $user->update(['selected_ticket_options' => $selected_ticket_optionsJson]);

        // set session for ticket size
        Session::put('ticket_design', $request->input('ticket_design'));
        
        // set seesion for printer type
        Session::put('printer_type', $request->input('printer_type'));
        
        // Selected Store id put in session
        Session::put('selected_store_id', $store->id);
        
        // forget printer session
        Session::forget('printer');
        
        // set session for show core product and out of stock
        Session::put('show_core_product', $request->input('show_core_product'));
        Session::put('out_of_stock', $request->input('stock'));
        // returing the view we want plus all the information we have given it
        return view( $view , [
            'products' => $print_products,
            'store' => $store
        ]);
    }

    /**
     * Ticket printing import.
     * 
     * @param Request $request
     *
     * @return \Illuminate\View\View
     */
    public function processTicketUpload(Request $request) 
    {
        if (!Auth::user()->hasPermission('view_ticket_printing')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        $this->validate($request, [
            'csv' => 'required|file',
            'ticket_design' => 'required|string|in:normalticket,smallticket',
            'store_id' => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
        ]);
        $pathToFile = false;

        // if request has csv file
        if ($request->hasFile('csv')) {
            $filePrefix = date('YmdHis').'_'.rand(100000,999999).'_';
            $pathToFile = $request->csv->storeAs('ticket/upload',$filePrefix.$request->file('csv')->getClientOriginalName());
        }

        // if there is no path for file
        if(!$pathToFile) {
            return back()->with(['flashType' => 'danger', 'flashMessage' => 'Upload failed, please try again. If this problem persists please contact your local administrator.']);
        }
        
        $file_processor = new TicketPrintingProcessor();

        // fetch data in the form of array from csv file
        $fetchedSkus = $file_processor->fetchTicketPrintingSKUsFromfile(storage_path('app')."/".$pathToFile);

        if (is_array($fetchedSkus) && !empty($fetchedSkus['error_header'])) {
            return back()->with(['flashType' => 'danger', 'flashMessage' => 'Some header is missing from the import file']);
        } else if (is_array($fetchedSkus) && !empty($fetchedSkus['mandatory'])) {
            return back()->with(['flashType' => 'danger', 'flashMessage' => 'Required data is missing from the import file']);
        }  else if (is_array($fetchedSkus) && !empty($fetchedSkus['error'])) {
            return back()->with(['flashType' => 'danger', 'flashMessage' => 'Please add 200 sku ']);
        } else {
            
            // Selected Store id put in session
            Session::put('selected_store_id', $request->store_id);
            
           //process the sku result
           return $this->processSkuArrayResult($fetchedSkus,$request->all());
        }
    }

    /**
     * Print Product Ticket.
     * 
     * @param Request $request
     * 
     * @return \Illuminate\View\View
     */
    public function print(Request $request) 
    {
        if (!Auth::user()->hasPermission('view_ticket_printing')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }

        $this->validate($request, [
            'store_id' => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
        ]);
        
        //create printproduct array
        $print_products = [];

        $store_id = $request->input("store_id");
        // Fetch store Details
        $store = Store::findOrFail($store_id);
        // get first product of the login user for the selected store from DB 
        $product = TicketPrinting::where('user_id',\Auth::user()->id)->where('store_id',$store_id)->first();


        // save product in printproduct
        $print_product = $product;
        
        // delete product from database 
        $product->delete();
        $dnsid = new DNS1D;
        $print_product['barcodeImage'] = $dnsid->getBarcodeSVG($print_product['sku'], "C128",1,30);
        $view = 'ticket_printing.'.$print_product->size;
        array_push($print_products, $print_product);
        return view($view, [
            'products' => $print_products,
            'store' => $store
        ]);   

    }

    /**
     * Ticket Printing upload.
     * 
     * @param Request $request
     *
     * @return \Illuminate\View\View
     */
    public function viewTicketUpload(Request $request) 
    {
        if (!Auth::user()->hasPermission('view_ticket_printing')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }

        // return on upload page if request has data  
        if($request->user_store_id || $request->design || $request->user_hide_unit) {
            
            $this->validate($request, [
                'design' => 'required|string|in:normalticket,smallticket',
                'user_store_id' => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
                'user_hide_unit' => 'required|integer|in:0,1'
            ]);
            
            if(($request->is_core_product == 1) || ($request->is_price_import_product == 1)){
                // Validate the remaining the fields
                $this->validate($request, [
                    'printer_type' => 'required|string|in:on,off',
                    'user_hide_price' => 'required|int|in:0,1',
                    'user_hide_sku' => 'required|int|in:0,1',
                    'user_hide_barcode' => 'required|int|in:0,1',
                    'user_show_core_product' => 'required|int|in:0,1',
                    'user_hide_name_code' => 'required|int|in:0,1',
                    'user_hide_currency' => 'required|int|in:0,1',
                    'product_stock' => 'required|int|in:0,1',
                    'print_all' => 'required|string|in:0,1',
                    'is_core_product' => 'required|int|in:0,1',
                    'is_price_import_product' => 'required|int|in:0,1',
                ]);

                $data = [
                    'ticket_design' => $request->design,
                    'printer_type' => $request->printer_type,
                    'store_id' => $request->user_store_id,
                    'hide_unit' => $request->user_hide_unit,
                    'hide_price' => $request->user_hide_price,
                    'hide_sku' => $request->user_hide_sku,
                    'hide_barcode' => $request->user_hide_barcode,
                    'hide_name_code' => $request->user_hide_name_code,
                    'hide_currency' => $request->user_hide_currency,
                    'stock' => $request->product_stock,
                    'print_all' => $request->print_all,
                    'is_core_product' => $request->is_core_product,
                    'is_price_import_product' => $request->is_price_import_product,
                    'show_core_product' => $request->user_show_core_product
                ];
                // Get the Applied Date to fetch the Core Range Products Update data
                $update_applied_dates = CoreRangeProductUpdate::orderBy('applied_date', 'desc')->distinct()->pluck('applied_date')->toArray() ?? [];
       
                $store_ids = Store::where('is_product_mgmt_enabled',1)->pluck('id')->toArray();

                if(strpos(\URL::previous(),'?referrer=store_update')){
                    $core_range_product_update_skus = CoreRangeProductTicketPrintingSku::pluck('sku')->toArray();
                    $data['show_positive_inventory'] = 1; //Printing all the products and not only positive stock products
                }else if (strpos(\URL::previous(), '?referrer=price_update_ticket')) {
                    $core_range_product_update_skus = PriceUpdateImportPrintTicket::pluck('sku')->toArray();

                    $data['show_positive_inventory'] = 1; //Printing all the products and not only positive stock products
                }else{
                    $core_range_product_update_skus = CoreRangeProductUpdate::select('core_range_product_updates.*')->distinct('sku')->where(function($store_ids){
                        foreach ($store_ids as $store_id){
                            $query->orWhere('core_range_product_updates.stores', 'LIKE', '%"' . $store_id . '"%');
                            }
                        })->where('core_range_product_updates.applied_date', $update_applied_dates[0])
                        ->orderBy('core_range_product_updates.id', 'DESC')->pluck('sku')->toArray();
                }
                
                //process the sku result
                return $this->processSkuArrayResult($core_range_product_update_skus,$data);
            }
            $data = $request->all();
            return view('ticket_printing.ticket_upload',compact('data'));  
        }

        // get all products of the login user for the selected store for print from DB 
        $store_id = Session::get('selected_store_id');
        
        $products = TicketPrinting::where('user_id',\Auth::user()->id)->where('store_id',$store_id)->get();

        // return  on list if there is a product
        if (count($products)>0) {
            return view( 'ticket_printing/ticket_list' , [
                'products' => $products
            ]);
        }

        // return if all tickets from files printed successfully
        return redirect()->route('ticket_printing')->with(['flashType' => 'success', 'flashMessage' => 'All Tickets are printed successfully!']);

    }

    /**
     * Download the Ticket upload template file
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\RedirectResponse
     */
    public function downloadTicketUploadTemplateFile(Request $request)
    {
        if (!Auth::user()->hasPermission('view_ticket_printing')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        
        // file path
        $template_file = storage_path('app').'/ticket/templatefiles/ticket_upload_template.csv';
        
        if(file_exists($template_file)) {
            return response()->download($template_file);
        } else {
            return back()->with(['flashType' => 'danger', 'flashMessage' => 'Could not find template file']);
        }
    }
  

    /**
    * Show all Ticket unit page.
    *
    * @return \Illuminate\View\View
    */
    public function viewUnits() {
        if (!Auth::user()->hasPermission('view_ticket_units') && !Auth::user()->hasPermission('readonly_ticket_units')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        
        $units = TicketUnit::all();
        
        return view('ticket_printing.ticket_unit', [
            'units' => $units,
        ]);
    }

    /**
    * Show the create Ticket unit page.
    *
    * @return \Illuminate\View\View
    */
    public function viewNewUnit() {  
        if (!Auth::user()->hasPermission('view_ticket_units')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        
        return view('ticket_printing.create_ticket_unit');
    }


    /**
    * Create a new Ticket Unit.
    *
    * @param Request $request
    * @return \Illuminate\Http\Response
    */
    public function createTicketUnit(Request $request) 
    {
        if (!Auth::user()->hasPermission('view_ticket_units')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }

        $validator = Validator::make($request->all(), [
            'symbol_name' => 'required|string|max:255',
            'symbol' => 'required|string|max:255|unique:ticket_unit',
            'divide_by' => 'required|integer',
            'unit' => 'required|string|max:255'
        ]);
        if ($validator->fails()) {
           return back()->with(['flashType' => 'danger', 'flashMessage' => 'The information you have entered is not allowed please try again - You cannot have the same symbol as a unit that already exists']);
        }

        TicketUnit::create([
            'symbol_name'   => $request->symbol_name,
            'symbol'        => $request->symbol,
            'divide_by'     => $request->divide_by,
            'unit'          => $request->unit
        ]);
        
        return redirect()->route('ticket_unit')->with(['flashType' => 'success', 'flashMessage' => 'Unit added successfully!']);
    }
    /**
    * Edit the specific Ticket unit.
    *
    * @param Integer $id  ID of the Ticket Unit to delete 
    * @return \Illuminate\Http\Response
    */
    public function editTicketUnit($id) 
    {
        if (!Auth::user()->hasPermission('view_ticket_units')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        $unit = TicketUnit::findorfail($id);
        return view('ticket_printing.edit_ticket_unit', [
            'unit' => $unit,
        ]);
    }
    /**
    * Update Ticket Unit.
    *
    * @param Request $request
    * @return \Illuminate\Http\Response
    */
    public function updateTicketUnit(Request $request, $id) 
    {
        if (!Auth::user()->hasPermission('view_ticket_units')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }

        $unit = TicketUnit::findorfail($id);

        $validator = Validator::make($request->all(), [
            'symbol_name' => 'required|string|max:255',
            'symbol' => 'required|max:255|unique:ticket_unit,symbol,'.$id,
            'divide_by' => 'required|integer',
            'unit' => 'required|string|max:255'
        ]);

        $validator->validate();
        

        $unit->symbol_name  = $request->input("symbol_name");
        $unit->symbol       = $request->input("symbol");
        $unit->divide_by    = $request->input("divide_by");
        $unit->unit         = $request->input("unit");

        $unit->save();
        return back()->with(['flashType' => 'success', 'flashMessage' => 'Unit sucessfully updated!']);

        
    }

    /**
    * Deletes the specific Ticket unit.
    *
    * @param Integer $id  ID of the Ticket Unit to delete 
    * @return \Illuminate\Http\Response
    */
    public function destroyTicketUnit($id) 
    {
        if (!Auth::user()->hasPermission('view_ticket_units')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        $unit = TicketUnit::findorfail($id);
        if($unit){
            $unit->delete();
            return redirect()->route('ticket_unit')->with(['flashType' => 'success', 'flashMessage' => 'Unit deleted successfully!']);
        }
        return redirect()->back()->with(['flashType' => 'success', 'flashMessage' => 'Unit not found!']);
    }
    
    /**
    * Print Product Tickets for the scanned SKUs by the login user for the specified store.
    *
    * @param Request $request
    * @return \Illuminate\Http\Response
    */
    public function printScannedSkus(Request $request) {
        
        if (!Auth::user()->hasPermission('view_ticket_printing')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        
        // Check Validations
        $this->validate($request, [
            'ticket_design' => 'required|string|in:normalticket,smallticket',
            'store_id' => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
        ]);
        
        //Fetch Store Details
        $store_id = $request->input("store_id");
        $store = Store::findOrFail($store_id);
        
        //Get the Scanned Product SKUs list
        $scannedSkus=[];
        if((isset($request['printer_type'])) && ($request['printer_type'] == 'on')){
            $scannedSkuDetails = array_map('strtolower', $store->scanned_products->where('user_id',\Auth::user()->id)->where('is_printed',0)->pluck('sku')->ToArray());
        }else{
            $scannedSkuDetails = array_map('strtolower', $store->scanned_products->where('user_id',\Auth::user()->id)->pluck('sku')->ToArray());
        }
        $scannedSkus['sku']=$scannedSkuDetails;
        
        if (!is_array($scannedSkus) || empty($scannedSkus['sku'])) {
            return back()->with(['flashType' => 'danger', 'flashMessage' => 'No scanned SKU records to print.']);
        }   else {
            
            $scanned_skus_flag = $request['scanned_skus_flag']=true;
            //process the SKU result
            $final_product = $this->ticketPrintingService->fetchAndImportProductsFromSkuResult($scannedSkus,$request->all());
            if(((!is_array($final_product)) && ($final_product == false)) || (empty($final_product))){
                return redirect('/ticket/print')->with(['flashType' => 'danger', 'flashMessage' => 'Could not match SKUs to a Vend product to print.']);
            }

            if(Session::get('printer_type') == "on"){
                // Set  Printer true
                Session::put('printer', true);
            }else{
                // forget printer session
                Session::forget('printer');
            }
            
            if(!empty($final_product['print_products'])){
                $view = 'ticket_printing/ticket_list';
                $products = $final_product['print_products'];
                $store = $final_product['store'];
            } else if(!empty($final_product['multi_products'])){
                $view = 'ticket_printing.'.$request->input('ticket_design');
                $products = $final_product['multi_products'];
                $store = $final_product['store'];
            } else{
                return back()->with(['flashType' => 'danger', 'flashMessage' => 'No scanned SKU products to print.']);
            }
           
            return view($view, compact('products','store_id','scanned_skus_flag','store')); 
        }
    }
    
    /**
     *  Delete scanned SKUs(via App) of login user for given store_id
     *
     *  @return \Illuminate\Http\Response
     */
    public function deleteScannedSkus(Request $request)
    {
        if (! \Auth::user()->hasPermission('view_ticket_printing')) {
            return response()->json(['error' => 'You do not have the necessary permissions to perform that operation'], 403);
        }
        
        // Check Validations
        $this->validate($request, [
            'store_id' => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
            'sku' => 'nullable|max:255|alpha_dash',
        ]);

        // Fetch Store Details
        $store = Store::findOrFail($request['store_id']);

        if(isset($request['sku']) && (!empty($request['sku']))){
            $store->scanned_products()->where('user_id',\Auth::user()->id)->where('sku',$request['sku'])->delete();
        }else{
            $store->scanned_products()->where('user_id',\Auth::user()->id)->delete();
        }
        
        return response()->json(['status' => 'true']);
    }
    
    /**
     * Print Product Ticket for Each Scanned SKU(via App) of login user (In case of receipt Printer)
     *
     * @params Request $request
     * 
     * @return \Illuminate\View\View
     */
    public function printoutScannedSkus(Request $request) 
    {
        if (!Auth::user()->hasPermission('view_ticket_printing')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        
        $this->validate($request, [
            'store_id' => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
            'size' => 'required|string|in:normalticket,smallticket',
            'sku' => 'required|max:255|alpha_dash',
        ]);
        
        // Fetch Store Details
        $store = Store::findOrFail($request->input("store_id"));
        
        // Fetch Scanned SKUs count related to the store 
        $scannedSkuCount = $store->scanned_products->where('user_id',\Auth::user()->id)->where('is_printed',0)->count();
        
        //Set the Scanned products Array for printing
        $printProducts =[];
        $printProduct = $request->all();
        $dnsid = new DNS1D;
        $printProduct['barcodeImage'] =$dnsid->getBarcodeSVG($printProduct["sku"], "C128",1.3,30);
        array_push($printProducts, $printProduct);
        
        //Set the view for printing
        $view = 'ticket_printing.'.$printProduct["size"];
        
        return view($view, [
            'products' => $printProducts,
            'store_id' => $request->input("store_id"),
            'scanned_skus_flag' => true,
            'scanned_sku_count' => $scannedSkuCount,
            'store' => $store
        ]);   

    }
    
    /**
     *  Update Print Status of the scanned SKU for loin user & given store_id(In case of Receipt Printer, we need to update the printing status flag as we are deleting the SKUs at the end)
     *
     *  @return \Illuminate\Http\Response
     */
    public function updateScannedSkuPrintStatus(Request $request)
    {
        if (! \Auth::user()->hasPermission('view_ticket_printing')) {
            return response()->json(['error' => 'You do not have the necessary permissions to perform that operation'], 403);
        }
        
        // Check Validations
        $this->validate($request, [
            'store_id' => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
            'sku' => 'nullable|max:255|alpha_dash',
        ]);

        // Fetch Scanned Product Details
        $store = Store::findOrFail($request['store_id']);
        $scannedProduct = $store->scanned_products->where('user_id',\Auth::user()->id)->where('sku',$request['sku'])->where('is_printed',0)->first();

        //Update Print Status
        if($scannedProduct){
            $scannedProduct->is_printed = 1;
            $scannedProduct->save();
        }
        
        return response()->json(['status' => 'true']);
    }
    
    /**
     *  Reset Print Status of the scanned SKUs for loin user & given store_id, (In case of Receipt Printer, we need to reset the printing status flag if the SKUs are not deleted at the end)
     *
     *  @return \Illuminate\Http\Response
     */
    public function resetScannedSkusPrintStatus(Request $request)
    {
        if (! \Auth::user()->hasPermission('view_ticket_printing')) {
            return response()->json(['error' => 'You do not have the necessary permissions to perform that operation'], 403);
        }
        
        // Check Validations
        $this->validate($request, [
            'store_id' => 'required|integer|in:'.implode(',', Auth::user()->getVisibleStores()->pluck('id')->all()),
        ]);

        // Reset Scanned Products Print Status
        ScannedProduct::where('user_id',\Auth::user()->id)->where('store_id',$request['store_id'])->update(['is_printed'=>0]);

        return response()->json(['status' => 'true']);
    }
      
    /**
     * Process SKU's Array Result set for ticking printing
     *
     * @params SKU's $skus_array
     * @params Request $data
     * 
     * @return \Illuminate\View\View
     */
    private function processSkuArrayResult($skus_array, $data){
        //process the sku result
        $final_product = $this->ticketPrintingService->fetchAndImportProductsFromSkuResult($skus_array,$data);
        if(($data['is_core_product'] == 0) && (((!is_array($final_product)) && ($final_product == false)) || (empty($final_product)))){
            return redirect('/ticket/print')->with(['flashType' => 'danger', 'flashMessage' => 'Could not match SKUs to a Vend product to print.']);
        }elseif(($data['is_core_product'] == 1) && (((!is_array($final_product)) && ($final_product == false)) || (empty($final_product)))){
            return redirect('/ticket/print')->with(['flashType' => 'danger', 'flashMessage' => 'Could not found Core Range Products to print.']);
        }
        elseif (($data['is_price_import_product'] == 1) && (((!is_array($final_product)) && ($final_product == false)) || (empty($final_product)))) {
            return redirect('/ticket/print')->with(['flashType' => 'danger', 'flashMessage' => 'Could not found Core Range Products to print.']);
        }

        // If Receipt Printer (print_products), then we need to first display the listing of all products & then printing it one by one
        // If Label Printer (multi_products), then we need to print all the multiple products in one go.
        if(Session::get('printer_type') == "on"){
            // Set  Printer true
            Session::put('printer', true);
        }else{
            // forget printer session
            Session::forget('printer');
        }

        if(!empty($final_product['print_products'])){
            return view( 'ticket_printing/ticket_list' , [
                'products' => $final_product['print_products'],
                'store' => $final_product['store']
            ]);   
        } else {
            $view = 'ticket_printing.'.$data['ticket_design'];

            return view($view, [
                'products' => $final_product['multi_products'],
                'store' => $final_product['store']
            ]);  
        } 
    }

    /**
     * Handle an Ajax request to update the printer type selected by the user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
    */
    public function updateSelectedPrinterType(Request $request){
        if (!Auth::user()->hasPermission('view_ticket_printing')) {
            return redirect()->home()->with(['flashType' => 'danger', 'flashMessage' => 'You do not have the necessary permissions to perform that operation']);
        }
        //Check Validations
        $validator = Validator::make($request->all(), [
            'printer_type'  => 'required|string|in:on,off',
            'print_all' => 'required|string|in:true,false'
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->messages()], 403);
        }
        // Update the printer type selected by the user
        // 1- label printer, 2- receipt printer, 3- receipt printer with disable cut
        $selected_printer_type = (($request['printer_type'] == "on") && ($request['print_all'] == 'true')) ? 3 : (($request['printer_type'] == "off") ? 1 : 2);
        Auth::user()->update(['selected_printer_type' => $selected_printer_type]);
        return response()->json($request['print_all'] , 200);
    }
}
