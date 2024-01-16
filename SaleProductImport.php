<?php

namespace CTReporting;

use Illuminate\Database\Eloquent\Model;

class SaleProductImport extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sale_product_imports'; //defines table

    protected $fillable = [ 'user_id', 'store_id', 'customer', 'sku', 'quantity', 'price', 'discount', 'error', 'row_number', 'notes'];
    
    /**
     * Many-To-One Relationship Method for accessing the user record
     *
     * @return QueryBuilder Object
     */
    public function user() {
        return $this->belongsTo('CTReporting\User');
    }
    
    /**
     * Many-To-One Relationship Method for accessing the store the sale product import belongs to
     *
     * @return QueryBuilder Object
     */
    public function store() {
        return $this->belongsTo('CTReporting\Store');
    }
}

