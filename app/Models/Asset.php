<?php

namespace App\Models;

use App\Events\AssetCheckedOut;
use App\Events\CheckoutableCheckedOut;
use App\Exceptions\CheckoutNotAllowed;
use App\Helpers\Helper;
use App\Http\Traits\UniqueSerialTrait;
use App\Http\Traits\UniqueUndeletedTrait;
use App\Models\Traits\Acceptable;
use App\Models\Traits\Searchable;
use App\Presenters\Presentable;
use AssetPresenter;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Watson\Validating\ValidatingTrait;

/**
 * Model for Assets.
 *
 * @version    v1.0
 */
class Asset extends Depreciable
{

    protected $presenter = \App\Presenters\AssetPresenter::class;

    use CompanyableTrait;
    use HasFactory, Loggable, Requestable, Presentable, SoftDeletes, ValidatingTrait, UniqueUndeletedTrait, UniqueSerialTrait;

    const LOCATION = 'location';
    const ASSET = 'asset';
    const USER = 'user';

    use Acceptable;

    /**
     * Run after the checkout acceptance was declined by the user
     * 
     * @param  User   $acceptedBy
     * @param  string $signature
     */ 
    public function declinedCheckout(User $declinedBy, $signature)
    {
      $this->assigned_to = null;
      $this->assigned_type = null;
      $this->accepted = null;      
      $this->save();        
    }

    /**
    * The database table used by the model.
    *
    * @var string
    */
    protected $table = 'assets';

    /**
    * Whether the model should inject it's identifier to the unique
    * validation rules before attempting validation. If this property
    * is not set in the model it will default to true.
    *
     * @var bool
    */
    protected $injectUniqueIdentifier = true;

    // We set these as protected dates so that they will be easily accessible via Carbon
    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
        'purchase_date',
        'last_checkout',
        'expected_checkin',
        'last_audit_date',
        'next_audit_date'
    ];


    protected $casts = [
        'purchase_date' => 'datetime',
        'last_checkout' => 'datetime',
        'expected_checkin' => 'datetime',
        'last_audit_date' => 'datetime',
        'next_audit_date' => 'datetime',
        'model_id'       => 'integer',
        'status_id'      => 'integer',
        'company_id'     => 'integer',
        'location_id'    => 'integer',
        'rtd_company_id' => 'integer',
        'supplier_id'    => 'integer',
        'byod'           => 'boolean',
    ];

    protected $rules = [
        'name'            => 'max:255|nullable',
        'model_id'        => 'required|integer|exists:models,id',
        'status_id'       => 'required|integer|exists:status_labels,id',
        'company_id'      => 'integer|nullable',
        'warranty_months' => 'numeric|nullable|digits_between:0,240',
        'physical'        => 'numeric|max:1|nullable',
        'checkout_date'   => 'date|max:10|min:10|nullable',
        'checkin_date'    => 'date|max:10|min:10|nullable',
        'location_id'     => 'exists:locations,id|nullable',
        'rtd_location_id' => 'exists:locations,id|nullable',
        'asset_tag'       => 'required|min:1|max:255|unique_undeleted',
        'status'          => 'integer',
        'serial'          => 'unique_serial|nullable',
        'purchase_cost'   => 'numeric|nullable|gte:0',
        'next_audit_date' => 'date|nullable',
        'last_audit_date' => 'date|nullable',
        'supplier_id'     => 'exists:suppliers,id|nullable',
    ];

  /**
   * The attributes that are mass assignable.
   *
   * @var array
   */
    protected $fillable = [
        'asset_tag',
        'assigned_to',
        'assigned_type',
        'company_id',
        'image',
        'location_id',
        'model_id',
        'name',
        'notes',
        'order_number',
        'purchase_cost',
        'purchase_date',
        'rtd_location_id',
        'serial',
        'status_id',
        'supplier_id',
        'warranty_months',
        'requestable',
        'last_checkout',
        'expected_checkin',
        'byod',
    ];

    use Searchable;

    /**
     * The attributes that should be included when searching the model.
     * 
     * @var array
     */
    protected $searchableAttributes = [
      'name', 
      'asset_tag', 
      'serial', 
      'order_number', 
      'purchase_cost', 
      'notes', 
      'created_at',
      'updated_at',      
      'purchase_date', 
      'expected_checkin', 
      'next_audit_date', 
      'last_audit_date',
    ];

    /**
     * The relations and their attributes that should be included when searching the model.
     * 
     * @var array
     */
    protected $searchableRelations = [
        'assetstatus'        => ['name'],
        'supplier'           => ['name'],
        'company'            => ['name'],
        'defaultLoc'         => ['name'],
        'location'           => ['name'],
        'model'              => ['name', 'model_number'],
        'model.category'     => ['name'],
        'model.manufacturer' => ['name'],
    ];


    /**
     * This handles the custom field validation for assets
     *
     * @var array
     */
    public function save(array $params = [])
    {
        if ($this->model_id != '') {
            $model = AssetModel::find($this->model_id);

            if (($model) && ($model->fieldset)) {

                foreach ($model->fieldset->fields as $field){
                    if($field->format == 'BOOLEAN'){
                        $this->{$field->db_column} = filter_var($this->{$field->db_column}, FILTER_VALIDATE_BOOLEAN);
                    }
                }

                $this->rules += $model->fieldset->validation_rules();

                foreach ($this->model->fieldset->fields as $field){
                    if($field->format == 'BOOLEAN'){
                        $this->{$field->db_column} = filter_var($this->{$field->db_column}, FILTER_VALIDATE_BOOLEAN);
                    }
                }
            }
        }



        return parent::save($params);
    }


    public function getDisplayNameAttribute()
    {
        return $this->present()->name();
    }

    /**
     * Returns the warranty expiration date as Carbon object
     * @return \Carbon|null
     */
    public function getWarrantyExpiresAttribute()
    {
        if (isset($this->attributes['warranty_months']) && isset($this->attributes['purchase_date'])) {
            if (is_string($this->attributes['purchase_date']) || is_string($this->attributes['purchase_date'])) {
                $purchase_date = \Carbon\Carbon::parse($this->attributes['purchase_date']);
            } else {
                $purchase_date = \Carbon\Carbon::instance($this->attributes['purchase_date']);
            }
            $purchase_date->setTime(0, 0, 0);

            return $purchase_date->addMonths((int) $this->attributes['warranty_months']);
        }

        return null;
    }


    /**
     * Establishes the asset -> company relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v3.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function company()
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    /**
     * Determines if an asset is available for checkout.
     * This checks to see if the it's checked out to an invalid (deleted) user
     * OR if the assigned_to and deleted_at fields on the asset are empty AND
     * that the status is deployable
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v3.0]
     * @return bool
     */
    public function availableForCheckout()
    {

        // This asset is not currently assigned to anyone and is not deleted...
        if ((! $this->assigned_to) && (! $this->deleted_at)) {

            // The asset status is not archived and is deployable
            if (($this->assetstatus) && ($this->assetstatus->archived == '0')
                && ($this->assetstatus->deployable == '1')) 
            {
                return true;

            }
        }
        return false;
    }


    /**
     * Checks the asset out to the target
     *
     * @todo The admin parameter is never used. Can probably be removed.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param User $user
     * @param User $admin
     * @param Carbon $checkout_at
     * @param Carbon $expected_checkin
     * @param string $note
     * @param null $name
     * @return bool
     * @since [v3.0]
     * @return bool
     */
    public function checkOut($target, $admin = null, $checkout_at = null, $expected_checkin = null, $note = null, $name = null, $location = null)
    {
        if (! $target) {
            return false;
        }
        if ($this->is($target)) {
            throw new CheckoutNotAllowed('You cannot check an asset out to itself.');
        }

        if ($expected_checkin) {
            $this->expected_checkin = $expected_checkin;
        }

        $this->last_checkout = $checkout_at;
        $this->name = $name;

        $this->assignedTo()->associate($target);

        if ($location != null) {
            $this->location_id = $location;
        } else {
            if (isset($target->location)) {
                $this->location_id = $target->location->id;
            }
            if ($target instanceof Location) {
                $this->location_id = $target->id;
            }
        }

        if ($this->save()) {
            if (is_int($admin)) {
                $checkedOutBy = User::findOrFail($admin);
            } elseif (get_class($admin) === \App\Models\User::class) {
                $checkedOutBy = $admin;
            } else {
                $checkedOutBy = Auth::user();
            }
            event(new CheckoutableCheckedOut($this, $target, $checkedOutBy, $note));

            $this->increment('checkout_counter', 1);

            return true;
        }

        return false;
    }

    /**
     * Sets the detailedNameAttribute
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v3.0]
     * @return string
     */
    public function getDetailedNameAttribute()
    {
        if ($this->assignedto) {
            $user_name = $this->assignedto->present()->name();
        } else {
            $user_name = 'Unassigned';
        }

        return $this->asset_tag.' - '.$this->name.' ('.$user_name.') '.($this->model) ? $this->model->name : '';
    }

    /**
     * Pulls in the validation rules
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v3.0]
     * @return array
     */
    public function validationRules()
    {
        return $this->rules;
    }


    /**
     * Establishes the asset -> depreciation relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v3.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function depreciation()
    {
        return $this->model->belongsTo(\App\Models\Depreciation::class, 'depreciation_id');
    }


    /**
     * Get components assigned to this asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function components()
    {
        return $this->belongsToMany('\App\Models\Component', 'components_assets', 'asset_id', 'component_id')->withPivot('id', 'assigned_qty', 'created_at')->withTrashed();
    }


    /**
     * Get depreciation attribute from associated asset model
     *
     * @todo Is this still needed?
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function get_depreciation()
    {
        if (($this->model) && ($this->model->depreciation)) {
            return $this->model->depreciation;
        }
    }


    /**
     * Get uploads for this asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function uploads()
    {
        return $this->hasMany('\App\Models\Actionlog', 'item_id')
                  ->where('item_type', '=', Asset::class)
                  ->where('action_type', '=', 'uploaded')
                  ->whereNotNull('filename')
                  ->orderBy('created_at', 'desc');
    }

    /**
     * Determines whether the asset is checked out to a user
     *
     * Even though we allow allow for checkout to things beyond users
     * this method is an easy way of seeing if we are checked out to a user.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return bool
     */
    public function checkedOutToUser()
    {
      return $this->assignedType() === self::USER;
    }

    /**
     * Get the target this asset is checked out to
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function assignedTo()
    {
        return $this->morphTo('assigned', 'assigned_type', 'assigned_to')->withTrashed();
    }

    /**
     * Gets assets assigned to this asset
     *
     * Sigh.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function assignedAssets()
    {
        return $this->morphMany(self::class, 'assigned', 'assigned_type', 'assigned_to')->withTrashed();
    }


    /**
     * Get the asset's location based on the assigned user
     *
     * @todo Refactor this if possible. It's awful.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \ArrayObject
     */
    public function assetLoc($iterations = 1, $first_asset = null)
    {
        if (! empty($this->assignedType())) {
            if ($this->assignedType() == self::ASSET) {
                if (! $first_asset) {
                    $first_asset = $this;
                }
                if ($iterations > 10) {
                    throw new \Exception('Asset assignment Loop for Asset ID: '.$first_asset->id);
                }
                $assigned_to = self::find($this->assigned_to); //have to do this this way because otherwise it errors
                if ($assigned_to) {
                    return $assigned_to->assetLoc($iterations + 1, $first_asset);
                } // Recurse until we have a final location
            }
            if ($this->assignedType() == self::LOCATION) {
                if ($this->assignedTo) {
                    return $this->assignedTo;
                }

            }
            if ($this->assignedType() == self::USER) {
                if (($this->assignedTo) && $this->assignedTo->userLoc) {
                    return $this->assignedTo->userLoc;
                }
                //this makes no sense
                return $this->defaultLoc;

            }

        }
        return $this->defaultLoc;
    }

    /**
     * Gets the lowercased name of the type of target the asset is assigned to
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return string
     */
    public function assignedType()
    {
        return strtolower(class_basename($this->assigned_type));
    }

    /**
     * Get the asset's location based on default RTD location
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v2.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function defaultLoc()
    {
        return $this->belongsTo(\App\Models\Location::class, 'rtd_location_id');
    }

    /**
     * Get the image URL of the asset.
     *
     * Check first to see if there is a specific image uploaded to the asset,
     * and if not, check for an image uploaded to the asset model.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v2.0]
     * @return string | false
     */
    public function getImageUrl()
    {
        if ($this->image && ! empty($this->image)) {
            return Storage::disk('public')->url(app('assets_upload_path').e($this->image));
        } elseif ($this->model && ! empty($this->model->image)) {
            return Storage::disk('public')->url(app('models_upload_path').e($this->model->image));
        }

        return false;
    }


    /**
     * Get the asset's logs
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v2.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function assetlog()
    {
        return $this->hasMany(\App\Models\Actionlog::class, 'item_id')
                  ->where('item_type', '=', self::class)
                  ->orderBy('created_at', 'desc')
                  ->withTrashed();
    }

    /**
     * Get the list of checkouts for this asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v2.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function checkouts()
    {
        return $this->assetlog()->where('action_type', '=', 'checkout')
            ->orderBy('created_at', 'desc')
            ->withTrashed();
    }

    /**
     * Get the list of checkins for this asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v2.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function checkins()
    {
        return $this->assetlog()
            ->where('action_type', '=', 'checkin from')
            ->orderBy('created_at', 'desc')
            ->withTrashed();
    }

    /**
     * Get the asset's user requests
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v2.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function userRequests()
    {
        return $this->assetlog()
            ->where('action_type', '=', 'requested')
            ->orderBy('created_at', 'desc')
            ->withTrashed();
    }


    /**
     * Get maintenances for this asset
     *
     * @author  Vincent Sposato <vincent.sposato@gmail.com>
     * @since 1.0
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function assetmaintenances()
    {
        return $this->hasMany(\App\Models\AssetMaintenance::class, 'asset_id')
                  ->orderBy('created_at', 'desc');
    }

    /**
     * Get action logs history for this asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v1.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function adminuser()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }



    /**
     * Establishes the asset -> status relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v1.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function assetstatus()
    {
        return $this->belongsTo(\App\Models\Statuslabel::class, 'status_id');
    }

    /**
     * Establishes the asset -> model relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v1.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function model()
    {
        return $this->belongsTo(\App\Models\AssetModel::class, 'model_id')->withTrashed();
    }

    /**
     * Return the assets with a warranty expiring within x days
     *
     * @param $days
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v2.0]
     * @return mixed
     */
    public static function getExpiringWarrantee($days = 30)
    {
        $days = (is_null($days)) ? 30 : $days;

        return self::where('archived', '=', '0')
            ->whereNotNull('warranty_months')
            ->whereNotNull('purchase_date')
            ->whereNull('deleted_at')
            ->whereRaw('DATE_ADD(`purchase_date`,INTERVAL `warranty_months` MONTH) <= DATE(NOW() + INTERVAL '
                                 . $days
                                 . ' DAY) AND DATE_ADD(`purchase_date`, INTERVAL `warranty_months` MONTH) > NOW()')
            ->orderByRaw('DATE_ADD(`purchase_date`,INTERVAL `warranty_months` MONTH)')
            ->get();
    }


    /**
     * Establishes the asset -> assigned licenses relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function licenses()
    {
        return $this->belongsToMany(\App\Models\License::class, 'license_seats', 'asset_id', 'license_id');
    }

    /**
     * Establishes the asset -> status relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function licenseseats()
    {
        return $this->hasMany(\App\Models\LicenseSeat::class, 'asset_id');
    }

    /**
     * Establishes the asset -> aupplier relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v2.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function supplier()
    {
        return $this->belongsTo(\App\Models\Supplier::class, 'supplier_id');
    }

    /**
     * Establishes the asset -> location relationship
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v2.0]
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function location()
    {
        return $this->belongsTo(\App\Models\Location::class, 'location_id');
    }



    /**
     * Get the next autoincremented asset tag
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return string | false
     */
    public static function autoincrement_asset()
    {
        $settings = \App\Models\Setting::getSettings();


        if ($settings->auto_increment_assets == '1') {
            $temp_asset_tag = \DB::table('assets')
                ->where('physical', '=', '1')
                ->max('asset_tag');

            $asset_tag_digits = preg_replace('/\D/', '', $temp_asset_tag);
            $asset_tag = preg_replace('/^0*/', '', $asset_tag_digits);

            if ($settings->zerofill_count > 0) {
                return $settings->auto_increment_prefix.self::zerofill($settings->next_auto_tag_base, $settings->zerofill_count);
            }

            return $settings->auto_increment_prefix.$settings->next_auto_tag_base;
        } else {
            return false;
        }
    }


    /**
     * Get the next base number for the auto-incrementer.
     *
     * We'll add the zerofill and prefixes on the fly as we generate the number.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return int
     */
    public static function nextAutoIncrement($assets)
    {

        $max = 1;

        foreach ($assets as $asset) {
            $results = preg_match("/\d+$/", $asset['asset_tag'], $matches);

            if ($results) 
            {
                $number = $matches[0];

                if ($number > $max) 
                {
                    $max = $number;
                }
            }
        }


    }



    /**
     * Add zerofilling based on Settings
     *
     * We'll add the zerofill and prefixes on the fly as we generate the number.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return string
     */
    public static function zerofill($num, $zerofill = 3)
    {
        return str_pad($num, $zerofill, '0', STR_PAD_LEFT);
    }

    /**
     * Determine whether to send a checkin/checkout email based on
     * asset model category
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return bool
     */
    public function checkin_email()
    {
        if (($this->model) && ($this->model->category)) {
            return $this->model->category->checkin_email;
        }
    }

    /**
     * Determine whether this asset requires acceptance by the assigned user
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return bool
     */
    public function requireAcceptance()
    {
        if (($this->model) && ($this->model->category)) {
            return $this->model->category->require_acceptance;
        }

    }

    /**
     * Checks for a category-specific EULA, and if that doesn't exist,
     * checks for a settings level EULA
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return string | false
     */
    public function getEula()
    {

        if (($this->model) && ($this->model->category)) {
            if ($this->model->category->eula_text) {
                return Helper::parseEscapedMarkedown($this->model->category->eula_text);
            } elseif ($this->model->category->use_default_eula == '1') {
                return Helper::parseEscapedMarkedown(Setting::getSettings()->default_eula_text);
            } else {
                return false;
            }
        }

        return false;
    }


    /**
    * -----------------------------------------------
    * BEGIN QUERY SCOPES
    * -----------------------------------------------
    **/

    /**
     * Run additional, advanced searches.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  array  $terms The search terms
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function advancedTextSearch(Builder $query, array $terms)
    {

        /**
         * Assigned user
         */
        $query = $query->leftJoin('users as assets_users', function ($leftJoin) {
            $leftJoin->on('assets_users.id', '=', 'assets.assigned_to')
                ->where('assets.assigned_type', '=', User::class);
        });

        foreach ($terms as $term) {

            $query = $query
                ->orWhere('assets_users.first_name', 'LIKE', '%'.$term.'%')
                ->orWhere('assets_users.last_name', 'LIKE', '%'.$term.'%')
                ->orWhere('assets_users.username', 'LIKE', '%'.$term.'%')
                ->orWhereRaw('CONCAT('.DB::getTablePrefix().'assets_users.first_name," ",'.DB::getTablePrefix().'assets_users.last_name) LIKE ?', ["%$term%"]);

        }

        /**
         * Assigned location
         */
        $query = $query->leftJoin('locations as assets_locations', function ($leftJoin) {
            $leftJoin->on('assets_locations.id', '=', 'assets.assigned_to')
                ->where('assets.assigned_type', '=', Location::class);
        });

        foreach ($terms as $term) {

            $query = $query->orWhere('assets_locations.name', 'LIKE', '%'.$term.'%');
        }

        /**
         * Assigned assets
         */
        $query = $query->leftJoin('assets as assigned_assets', function ($leftJoin) {
            $leftJoin->on('assigned_assets.id', '=', 'assets.assigned_to')
                ->where('assets.assigned_type', '=', self::class);
        });

        foreach ($terms as $term) {
            $query = $query->orWhere('assigned_assets.name', 'LIKE', '%'.$term.'%');

        }

        return $query;
    }


    /**
    * Query builder scope for hardware
    *
    * @param  \Illuminate\Database\Query\Builder $query Query builder instance
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */

    public function scopeHardware($query)
    {
        return $query->where('physical', '=', '1');
    }

    /**
    * Query builder scope for pending assets
    *
    * @param  \Illuminate\Database\Query\Builder $query Query builder instance
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */

    public function scopePending($query)
    {
        return $query->whereHas('assetstatus', function ($query) {
            $query->where('deployable', '=', 0)
                ->where('pending', '=', 1)
                ->where('archived', '=', 0);
        });
    }


    /**
    * Query builder scope for searching location
    *
    * @param  \Illuminate\Database\Query\Builder $query Query builder instance
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */

    public function scopeAssetsByLocation($query, $location)
    {
        return $query->where(function ($query) use ($location) {
            $query->whereHas('assignedTo', function ($query) use ($location) {
                $query->where([
                    ['users.location_id', '=', $location->id],
                    ['assets.assigned_type', '=', User::class],
                ])->orWhere([
                    ['locations.id', '=', $location->id],
                    ['assets.assigned_type', '=', Location::class],
                ])->orWhere([
                    ['assets.rtd_location_id', '=', $location->id],
                    ['assets.assigned_type', '=', self::class],
                ]);
            })->orWhere(function ($query) use ($location) {
                $query->where('assets.rtd_location_id', '=', $location->id);
                $query->whereNull('assets.assigned_to');
            });
        });
    }


    /**
    * Query builder scope for RTD assets
    *
    * @param  \Illuminate\Database\Query\Builder $query Query builder instance
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */

    public function scopeRTD($query)
    {
        return $query->whereNull('assets.assigned_to')
                   ->whereHas('assetstatus', function ($query) {
                       $query->where('deployable', '=', 1)
                             ->where('pending', '=', 0)
                             ->where('archived', '=', 0);
                   });
    }

  /**
   * Query builder scope for Undeployable assets
   *
   * @param  \Illuminate\Database\Query\Builder $query Query builder instance
   *
   * @return \Illuminate\Database\Query\Builder          Modified query builder
   */

    public function scopeUndeployable($query)
    {
        return $query->whereHas('assetstatus', function ($query) {
            $query->where('deployable', '=', 0)
                ->where('pending', '=', 0)
                ->where('archived', '=', 0);
        });
    }

    /**
     * Query builder scope for non-Archived assets
     *
     * @param  \Illuminate\Database\Query\Builder $query Query builder instance
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */

    public function scopeNotArchived($query)
    {
        return $query->whereHas('assetstatus', function ($query) {
            $query->where('archived', '=', 0);
        });
    }

    /**
     * Query builder scope for Assets that are due for auditing, based on the assets.next_audit_date
     * and settings.audit_warning_days.
     *
     * This is/will be used in the artisan command snipeit:upcoming-audits and also
     * for an upcoming API call for retrieving a report on assets that will need to be audited.
     *
     * Due for audit soon:
     * next_audit_date greater than or equal to now (must be in the future)
     * and (next_audit_date - threshold days) <= now ()
     *
     * Example:
     * next_audit_date = May 4, 2025
     * threshold for alerts = 30 days
     * now = May 4, 2019
     *
     * @author A. Gianotto <snipe@snipe.net>
     * @since v4.6.16
     * @param Setting $settings
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */

    public function scopeDueForAudit($query, $settings)
    {
        $interval = $settings->audit_warning_days ?? 0;

        return $query->whereNotNull('assets.next_audit_date')
            ->where('assets.next_audit_date', '>=', Carbon::now())
            ->whereRaw("DATE_SUB(assets.next_audit_date, INTERVAL $interval DAY) <= '".Carbon::now()."'")
            ->where('assets.archived', '=', 0)
            ->NotArchived();
    }

    /**
     * Query builder scope for Assets that are OVERDUE for auditing, based on the assets.next_audit_date
     * and settings.audit_warning_days. It checks to see if assets.next audit_date is before now
     *
     * This is/will be used in the artisan command snipeit:upcoming-audits and also
     * for an upcoming API call for retrieving a report on overdue assets.
     *
     * @author A. Gianotto <snipe@snipe.net>
     * @since v4.6.16
     * @param Setting $settings
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */

    public function scopeOverdueForAudit($query)
    {
        return $query->whereNotNull('assets.next_audit_date')
            ->where('assets.next_audit_date', '<', Carbon::now())
            ->where('assets.archived', '=', 0)
            ->NotArchived();
    }

    /**
     * Query builder scope for Assets that are due for auditing OR overdue, based on the assets.next_audit_date
     * and settings.audit_warning_days.
     *
     * This is/will be used in the artisan command snipeit:upcoming-audits and also
     * for an upcoming API call for retrieving a report on assets that will need to be audited.
     *
     * @author A. Gianotto <snipe@snipe.net>
     * @since v4.6.16
     * @param Setting $settings
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */

    public function scopeDueOrOverdueForAudit($query, $settings)
    {
        $interval = $settings->audit_warning_days ?? 0;

        return $query->whereNotNull('assets.next_audit_date')
            ->whereRaw('DATE_SUB('.DB::getTablePrefix()."assets.next_audit_date, INTERVAL $interval DAY) <= '".Carbon::now()."'")
            ->where('assets.archived', '=', 0)
            ->NotArchived();
    }


    /**
     * Query builder scope for Archived assets counting
     *
     * This is primarily used for the tab counters so that IF the admin
     * has chosen to not display archived assets in their regular lists
     * and views, it will return the correct number.
     *
     * @param  \Illuminate\Database\Query\Builder $query Query builder instance
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */

    public function scopeAssetsForShow($query)
    {

        if (Setting::getSettings()->show_archived_in_list!=1) {
            return $query->whereHas('assetstatus', function ($query) {
                $query->where('archived', '=', 0);
            });
        } else {
            return $query;
        }

    }

  /**
   * Query builder scope for Archived assets
   *
   * @param  \Illuminate\Database\Query\Builder $query Query builder instance
   *
   * @return \Illuminate\Database\Query\Builder          Modified query builder
   */

    public function scopeArchived($query)
    {
        return $query->whereHas('assetstatus', function ($query) {
            $query->where('deployable', '=', 0)
                ->where('pending', '=', 0)
                ->where('archived', '=', 1);
        });
    }

  /**
   * Query builder scope for Deployed assets
   *
   * @param  \Illuminate\Database\Query\Builder $query Query builder instance
   *
   * @return \Illuminate\Database\Query\Builder          Modified query builder
   */

    public function scopeDeployed($query)
    {
        return $query->where('assigned_to', '>', '0');
    }

  /**
   * Query builder scope for Requestable assets
   *
   * @param  \Illuminate\Database\Query\Builder $query Query builder instance
   *
   * @return \Illuminate\Database\Query\Builder          Modified query builder
   */

    public function scopeRequestableAssets($query)
    {
        $table = $query->getModel()->getTable();

        return Company::scopeCompanyables($query->where($table.'.requestable', '=', 1))
        ->whereHas('assetstatus', function ($query) {
            $query->where(function ($query) {
                $query->where('deployable', '=', 1)
                      ->where('archived', '=', 0); // you definitely can't request something that's archived
            })->orWhere('pending', '=', 1); // we've decided that even though an asset may be 'pending', you can still request it
        });
    }


    /**
   * scopeInModelList
   * Get all assets in the provided listing of model ids
   *
   * @param       $query
   * @param array $modelIdListing
   *
   * @return mixed
   * @author  Vincent Sposato <vincent.sposato@gmail.com>
   * @version v1.0
   */
    public function scopeInModelList($query, array $modelIdListing)
    {
        return $query->whereIn('assets.model_id', $modelIdListing);
    }

  /**
  * Query builder scope to get not-yet-accepted assets
  *
  * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
  *
  * @return \Illuminate\Database\Query\Builder          Modified query builder
  */
    public function scopeNotYetAccepted($query)
    {
        return $query->where('accepted', '=', 'pending');
    }

  /**
  * Query builder scope to get rejected assets
  *
  * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
  *
  * @return \Illuminate\Database\Query\Builder          Modified query builder
  */
    public function scopeRejected($query)
    {
        return $query->where('accepted', '=', 'rejected');
    }


  /**
  * Query builder scope to get accepted assets
  *
  * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
  *
  * @return \Illuminate\Database\Query\Builder          Modified query builder
  */
    public function scopeAccepted($query)
    {
        return $query->where('accepted', '=', 'accepted');
    }

    /**
     * Query builder scope to search on text for complex Bootstrap Tables API.
     *
     * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
     * @param  text                              $search      Search term
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */
    public function scopeAssignedSearch($query, $search)
    {
        $search = explode(' OR ', $search);

        return $query->leftJoin('users as assets_users', function ($leftJoin) {
            $leftJoin->on('assets_users.id', '=', 'assets.assigned_to')
                ->where('assets.assigned_type', '=', User::class);
        })->leftJoin('locations as assets_locations', function ($leftJoin) {
            $leftJoin->on('assets_locations.id', '=', 'assets.assigned_to')
                ->where('assets.assigned_type', '=', Location::class);
        })->leftJoin('assets as assigned_assets', function ($leftJoin) {
            $leftJoin->on('assigned_assets.id', '=', 'assets.assigned_to')
                ->where('assets.assigned_type', '=', self::class);
        })->where(function ($query) use ($search) {
            foreach ($search as $search) {
                $query->whereHas('model', function ($query) use ($search) {
                    $query->whereHas('category', function ($query) use ($search) {
                        $query->where(function ($query) use ($search) {
                            $query->where('categories.name', 'LIKE', '%'.$search.'%')
                                ->orWhere('models.name', 'LIKE', '%'.$search.'%')
                                ->orWhere('models.model_number', 'LIKE', '%'.$search.'%');
                        });
                    });
                })->orWhereHas('model', function ($query) use ($search) {
                    $query->whereHas('manufacturer', function ($query) use ($search) {
                        $query->where(function ($query) use ($search) {
                            $query->where('manufacturers.name', 'LIKE', '%'.$search.'%');
                        });
                    });
                })->orWhere(function ($query) use ($search) {
                    $query->where('assets_users.first_name', 'LIKE', '%'.$search.'%')
                        ->orWhere('assets_users.last_name', 'LIKE', '%'.$search.'%')
                        ->orWhereRaw('CONCAT('.DB::getTablePrefix().'assets_users.first_name," ",'.DB::getTablePrefix().'assets_users.last_name) LIKE ?', ["%$search%"])
                        ->orWhere('assets_users.username', 'LIKE', '%'.$search.'%')
                        ->orWhere('assets_locations.name', 'LIKE', '%'.$search.'%')
                        ->orWhere('assigned_assets.name', 'LIKE', '%'.$search.'%');
                })->orWhere('assets.name', 'LIKE', '%'.$search.'%')
                    ->orWhere('assets.asset_tag', 'LIKE', '%'.$search.'%')
                    ->orWhere('assets.serial', 'LIKE', '%'.$search.'%')
                    ->orWhere('assets.order_number', 'LIKE', '%'.$search.'%')
                    ->orWhere('assets.notes', 'LIKE', '%'.$search.'%');
            }

        })->withTrashed()->whereNull('assets.deleted_at'); //workaround for laravel bug
    }

    /**
     * Query builder scope to search the department ID of users assigned to assets
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v5.0]
     * @return string | false
     *
     * @return \Illuminate\Database\Query\Builder Modified query builder
     */
    public function scopeCheckedOutToTargetInDepartment($query, $search)
    {
        return $query->leftJoin('users as assets_dept_users', function ($leftJoin) {
            $leftJoin->on('assets_dept_users.id', '=', 'assets.assigned_to')
                ->where('assets.assigned_type', '=', User::class);
        })->where(function ($query) use ($search) {
                    $query->where('assets_dept_users.department_id', '=', $search);

        })->withTrashed()->whereNull('assets.deleted_at'); //workaround for laravel bug
    }



    /**
     * Query builder scope to search on text filters for complex Bootstrap Tables API
     *
     * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
     * @param  text   $filter   JSON array of search keys and terms
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */
    public function scopeByFilter($query, $filter)
    {
        return $query->where(function ($query) use ($filter) {
            foreach ($filter as $key => $search_val) {

                $fieldname = str_replace('custom_fields.', '', $key);

                if ($fieldname == 'asset_tag') {
                    $query->where('assets.asset_tag', 'LIKE', '%'.$search_val.'%');
                }

                if ($fieldname == 'name') {
                    $query->where('assets.name', 'LIKE', '%'.$search_val.'%');
                }


                if ($fieldname =='serial') {
                    $query->where('assets.serial', 'LIKE', '%'.$search_val.'%');
                }

                if ($fieldname == 'purchase_date') {
                    $query->where('assets.purchase_date', 'LIKE', '%'.$search_val.'%');
                }

                if ($fieldname == 'purchase_cost') {
                    $query->where('assets.purchase_cost', 'LIKE', '%'.$search_val.'%');
                }

                if ($fieldname == 'notes') {
                    $query->where('assets.notes', 'LIKE', '%'.$search_val.'%');
                }

                if ($fieldname == 'order_number') {
                    $query->where('assets.order_number', 'LIKE', '%'.$search_val.'%');
                }

                if ($fieldname == 'status_label') {
                    $query->whereHas('assetstatus', function ($query) use ($search_val) {
                        $query->where('status_labels.name', 'LIKE', '%'.$search_val.'%');
                    });
                }

                if ($fieldname == 'location') {
                    $query->whereHas('location', function ($query) use ($search_val) {
                        $query->where('locations.name', 'LIKE', '%'.$search_val.'%');
                    });
                }

                if ($fieldname =='assigned_to') {
                    $query->whereHasMorph('assignedTo', [User::class], function ($query) use ($search_val) {
                        $query->where(function ($query) use ($search_val) {
                            $query->where('users.first_name', 'LIKE', '%'.$search_val.'%')
                                ->orWhere('users.last_name', 'LIKE', '%'.$search_val.'%');
                        });
                    });
                }


                if ($fieldname == 'manufacturer') {
                    $query->whereHas('model', function ($query) use ($search_val) {
                        $query->whereHas('manufacturer', function ($query) use ($search_val) {
                            $query->where(function ($query) use ($search_val) {
                                $query->where('manufacturers.name', 'LIKE', '%'.$search_val.'%');
                            });
                        });
                    });
                }

                if ($fieldname == 'category') {
                    $query->whereHas('model', function ($query) use ($search_val) {
                        $query->whereHas('category', function ($query) use ($search_val) {
                            $query->where(function ($query) use ($search_val) {
                                $query->where('categories.name', 'LIKE', '%'.$search_val.'%')
                                    ->orWhere('models.name', 'LIKE', '%'.$search_val.'%')
                                    ->orWhere('models.model_number', 'LIKE', '%'.$search_val.'%');
                            });
                        });
                    });
                }

                if ($fieldname == 'model') {
                    $query->where(function ($query) use ($search_val) {
                        $query->whereHas('model', function ($query) use ($search_val) {
                            $query->where('models.name', 'LIKE', '%'.$search_val.'%');
                        });
                    });
                }

                if ($fieldname == 'model_number') {
                    $query->where(function ($query) use ($search_val) {
                        $query->whereHas('model', function ($query) use ($search_val) {
                            $query->where('models.model_number', 'LIKE', '%'.$search_val.'%');
                        });
                    });
                }


                if ($fieldname == 'company') {
                    $query->where(function ($query) use ($search_val) {
                        $query->whereHas('company', function ($query) use ($search_val) {
                            $query->where('companies.name', 'LIKE', '%'.$search_val.'%');
                        });
                    });
                }

                if ($fieldname == 'supplier') {
                    $query->where(function ($query) use ($search_val) {
                        $query->whereHas('supplier', function ($query) use ($search_val) {
                            $query->where('suppliers.name', 'LIKE', '%'.$search_val.'%');
                        });
                    });
                }
            

            /**
             * THIS CLUNKY BIT IS VERY IMPORTANT
             *
             * Although inelegant, this section matters a lot when querying against fields that do not
             * exist on the asset table. There's probably a better way to do this moving forward, for
             * example using the Schema:: methods to determine whether or not a column actually exists,
             * or even just using the $searchableRelations variable earlier in this file.
             *
             * In short, this set of statements tells the query builder to ONLY query against an
             * actual field that's being passed if it doesn't meet known relational fields. This
             * allows us to query custom fields directly in the assetsv table
             * (regardless of their name) and *skip* any fields that we already know can only be
             * searched through relational searches that we do earlier in this method.
             *
             * For example, we do not store "location" as a field on the assets table, we store
             * that relationship through location_id on the assets table, therefore querying
             * assets.location would fail, as that field doesn't exist -- plus we're already searching
             * against those relationships earlier in this method.
             *
             * - snipe 
             *
             */

            if (($fieldname!='category') && ($fieldname!='model_number') && ($fieldname!='rtd_location') && ($fieldname!='location') && ($fieldname!='supplier')
                && ($fieldname!='status_label') && ($fieldname!='assigned_to') && ($fieldname!='model') && ($fieldname!='company') && ($fieldname!='manufacturer')) {
                    $query->where('assets.'.$fieldname, 'LIKE', '%' . $search_val . '%');
            }


            }


        });

    }


    /**
    * Query builder scope to order on model
    *
    * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
    * @param  text                              $order       Order
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */
    public function scopeOrderModels($query, $order)
    {
        return $query->join('models as asset_models', 'assets.model_id', '=', 'asset_models.id')->orderBy('asset_models.name', $order);
    }

    /**
    * Query builder scope to order on model number
    *
    * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
    * @param  text                              $order       Order
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */
    public function scopeOrderModelNumber($query, $order)
    {
        return $query->join('models', 'assets.model_id', '=', 'models.id')->orderBy('models.model_number', $order);
    }


    /**
    * Query builder scope to order on assigned user
    *
    * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
    * @param  text                              $order       Order
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */
    public function scopeOrderAssigned($query, $order)
    {
        return $query->leftJoin('users as users_sort', 'assets.assigned_to', '=', 'users_sort.id')->select('assets.*')->orderBy('users_sort.first_name', $order)->orderBy('users_sort.last_name', $order);
    }

    /**
    * Query builder scope to order on status
    *
    * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
    * @param  text                              $order       Order
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */
    public function scopeOrderStatus($query, $order)
    {
        return $query->join('status_labels as status_sort', 'assets.status_id', '=', 'status_sort.id')->orderBy('status_sort.name', $order);
    }

    /**
    * Query builder scope to order on company
    *
    * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
    * @param  text                              $order       Order
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */
    public function scopeOrderCompany($query, $order)
    {
        return $query->leftJoin('companies as company_sort', 'assets.company_id', '=', 'company_sort.id')->orderBy('company_sort.name', $order);
    }


    /**
     * Query builder scope to return results of a category
     *
     * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
     * @param  text $order Order
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */
    public function scopeInCategory($query, $category_id)
    {
        return $query->join('models as category_models', 'assets.model_id', '=', 'category_models.id')
            ->join('categories', 'category_models.category_id', '=', 'categories.id')->where('category_models.category_id', '=', $category_id);
    }

    /**
     * Query builder scope to return results of a manufacturer
     *
     * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
     * @param  text $order Order
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */
    public function scopeByManufacturer($query, $manufacturer_id)
    {
        return $query->join('models', 'assets.model_id', '=', 'models.id')
            ->join('manufacturers', 'models.manufacturer_id', '=', 'manufacturers.id')->where('models.manufacturer_id', '=', $manufacturer_id);
    }



    /**
    * Query builder scope to order on category
    *
    * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
    * @param  text                              $order         Order
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */
    public function scopeOrderCategory($query, $order)
    {
        return $query->join('models as order_model_category', 'assets.model_id', '=', 'order_model_category.id')
            ->join('categories as category_order', 'order_model_category.category_id', '=', 'category_order.id')
            ->orderBy('category_order.name', $order);
    }


    /**
     * Query builder scope to order on manufacturer
     *
     * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
     * @param  text                              $order         Order
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */
    public function scopeOrderManufacturer($query, $order)
    {
        return $query->join('models', 'assets.model_id', '=', 'models.id')
            ->join('manufacturers', 'models.manufacturer_id', '=', 'manufacturers.id')
            ->orderBy('manufacturers.name', $order);
    }

   /**
    * Query builder scope to order on location
    *
    * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
    * @param  text                              $order       Order
    *
    * @return \Illuminate\Database\Query\Builder          Modified query builder
    */
    public function scopeOrderLocation($query, $order)
    {
        return $query->leftJoin('locations as asset_locations', 'asset_locations.id', '=', 'assets.location_id')->orderBy('asset_locations.name', $order);
    }

    /**
     * Query builder scope to order on default
     * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
     * @param  text                              $order       Order
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */
    public function scopeOrderRtdLocation($query, $order)
    {
        return $query->leftJoin('locations as rtd_asset_locations', 'rtd_asset_locations.id', '=', 'assets.rtd_location_id')->orderBy('rtd_asset_locations.name', $order);
    }


    /**
     * Query builder scope to order on supplier name
     *
     * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
     * @param  text                              $order       Order
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */
    public function scopeOrderSupplier($query, $order)
    {
        return $query->leftJoin('suppliers as suppliers_assets', 'assets.supplier_id', '=', 'suppliers_assets.id')->orderBy('suppliers_assets.name', $order);
    }

    /**
     * Query builder scope to search on location ID
     *
     * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
     * @param  text                              $search      Search term
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */
    public function scopeByLocationId($query, $search)
    {
        return $query->where(function ($query) use ($search) {
            $query->whereHas('location', function ($query) use ($search) {
                $query->where('locations.id', '=', $search);
            });
        });

    }


    /**
     * Query builder scope to search on depreciation name
     * @param  \Illuminate\Database\Query\Builder  $query  Query builder instance
     * @param  text                              $search      Search term
     *
     * @return \Illuminate\Database\Query\Builder          Modified query builder
     */
    public function scopeByDepreciationId($query, $search)
    {
        return $query->join('models', 'assets.model_id', '=', 'models.id')
            ->join('depreciations', 'models.depreciation_id', '=', 'depreciations.id')->where('models.depreciation_id', '=', $search);

    }


}
