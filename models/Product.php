<?php
use Charity\Howto\Traits\Resourceable;
use Charity\Howto\Traits\WebinarApi;

/**
 * Product
 *
 * @property integer $id
 * @property string $author
 * @property string $name
 * @property string $slug
 * @property integer $infusionsoft_product_id
 * @property string $sku
 * @property integer $status
 * @property string $type
 * @property string $title
 * @property integer $duration
 * @property integer $featured
 * @property float $price
 * @property string $date
 * @property string $short_description
 * @property string $long_description
 * @property integer $order
 * @property string $large_image
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property string $level
 * @property string $resource
 * @property-read \Illuminate\Database\Eloquent\Collection|\Category[] $categories
 * @property-read \Illuminate\Database\Eloquent\Collection|\Variation[] $variations
 * @property integer $author_id
 * @property integer $infusionsoft_tag_id
 * @property integer $webform_id
 * @property string $product_image
 * @property-read \Webform $webform
 * @method static \Illuminate\Database\Query\Builder|\Product whereId($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereAuthorId($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereInfusionsoftProductId($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereInfusionsoftTagId($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereWebformId($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereName($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereSlug($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereLevel($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereSku($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereStatus($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereType($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereDuration($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereFeatured($value)
 * @method static \Illuminate\Database\Query\Builder|\Product wherePrice($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereShortDescription($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereLongDescription($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereProductImage($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereOrder($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereUpdatedAt($value)
 * @property string $infusionsoft_tags
 * @property string $image_url
 * @property-read \Illuminate\Database\Eloquent\Collection|\Resource[] $resources
 * @method static \Illuminate\Database\Query\Builder|\Product whereInfusionsoftTags($value)
 * @method static \Illuminate\Database\Query\Builder|\Product whereImageUrl($value)
 * @property-read \Illuminate\Database\Eloquent\Collection|\OrderItem[] $orderItems
 * @property-read \Illuminate\Database\Eloquent\Collection|\User[] $users
 * @property-read \Illuminate\Database\Eloquent\Collection|\MembershipLevel[] $membershipLevels
 */
class Product extends Eloquent {

    use Resourceable, WebinarApi;

    protected $guarded = [];

    public static $rules = array();

    static $_TYPE_DVD = 'dvd';
    static $_TYPE_E_BOOK = 'e-book';
    static $_TYPE_LIVE_WEBINAR = 'live-webinar';
    static $_TYPE_PREMIUM_COURSE = 'premium-course';
    static $_TYPE_RECORDED_WEBINAR = 'recorded-webinar';
    static $_TYPE_GOOGLE_GRANTS = 'google-grants';

    static $_LEVEL_BEGINNER = 'beginner';
    static $_LEVEL_BEGINNER_INTERMEDIATE = 'beginner-intermediate';
    static $_LEVEL_INTERMEDIATE = 'intermediate';
    static $_LEVEL_ADVANCED = 'advanced';

    static $_STATUS_ACTIVE = 'active';
    static $_STATUS_INACTIVE = 'inactive';
    static $_STATUS_IMPORTED = 'imported';
    static $_STATUS_SOLD_OUT = 'sold-out';


    static function getTypeName($typeId = null)
    {
        if ($typeId !== null) {
            return self::getTypeName()[$typeId];
        }
        return [
            self::$_TYPE_LIVE_WEBINAR => 'Live Webinar',
            self::$_TYPE_RECORDED_WEBINAR => 'Recorded Webinar',
            self::$_TYPE_PREMIUM_COURSE => 'On Demand Tutorial',
            self::$_TYPE_DVD => 'DVD',
            self::$_TYPE_E_BOOK => 'E-Book',
            self::$_TYPE_GOOGLE_GRANTS => 'Google Grants',
        ];
    }

    static function getLevelName($levelId = null)
    {
        if ($levelId !== null) {
            return self::getLevelName()[$levelId];
        }
        return [
            self::$_LEVEL_BEGINNER => 'Beginner',
            self::$_LEVEL_BEGINNER_INTERMEDIATE => 'Beginner/Intermediate',
            self::$_LEVEL_INTERMEDIATE => 'Intermediate',
            self::$_LEVEL_ADVANCED => 'Advanced',
        ];
    }

    static function getStatusName($statusId = null)
    {
        if ($statusId !== null) {
            return self::getStatusName()[$statusId];
        }
        return [
            self::$_STATUS_ACTIVE => 'Active',
            self::$_STATUS_INACTIVE => 'Inactive',
            self::$_STATUS_SOLD_OUT => 'Sold Out',
            self::$_STATUS_IMPORTED => 'Imported',
        ];
    }

    /*
     * Helper functions
     */


    public function displayImageUrl()
    {
        if ($this->image_url) {
            return $this->image_url;
        }

        return 'https://s3.amazonaws.com/charityhowto-resources/static/products/product-default.gif'; // TODO: Refactor default product image to somewhere?
    }

    public function displayDuration()
    {
        if (!$this->duration) {
            return 'N/A';
        }

        $hours = floor($this->duration / 60);
        $minutes = $this->duration % 60;

        return ($hours ? "$hours Hour" . ($hours > 1 ? 's':'') : '') . ' ' . ($minutes ? "$minutes Minutes" : '');
    }

    public function displayPrice()
    {
        return $this->price == 0 ? 'FREE' : number_format($this->price, 2, '.', ',');
    }

    public function findVariation($variationId)
    {
        return $this->variations->keyBy('id')->get($variationId);
    }

    public function hasBonusMaterial()
    {
        foreach ($this->resources as $resource) {
            if ($resource->notification_type == Resource::$_NOTIFICATION_TYPE_BONUS_MATERIAL) {
                return true;
            }
        }

        return false;
    }

    public function hasPreviewResource()
    {
        foreach ($this->resources as $resource) {
            if ($resource->preview && $resource->isActive()) return true;
        }

        return false;
    }

    public function hasVideoPreviewResource()
    {
        foreach ($this->resources as $resource) {
            if ($resource->preview && $resource->isActive() && $resource->type == Resource::$_TYPE_VIDEO) return true;
        }

        return false;
    }

    public function hasWebinarRecording()
    {
        foreach ($this->resources as $resource) {
            if ($resource->notification_type == Resource::$_NOTIFICATION_TYPE_WEBINAR_RECORDING) {
                return true;
            }
        }

        return false;
    }

    public function hasActiveResource()
    {
        foreach ($this->resources as $resource) {
            if ($resource->status == Resource::$_STATUS_ACTIVE) {
                return true;
            }
        }

        if ($this->type == self::$_TYPE_LIVE_WEBINAR) {
            foreach ($this->variations as $variation) {

            }
        }
    }

    public function isSoldOut()
    {
        return $this->status == self::$_STATUS_SOLD_OUT;
    }

    public function isFree()
    {
        return $this->usesWebformCheckout();
    }

    public function primaryCategory()
    {
        return $this->categories->first();
    }

    public function usesWebformCheckout()
    {
        return $this->webform ? true : false;
    }

    public function variationList()
    {
        $variations = [];
        $this->variations->sortBy('date');
        foreach ($this->variations as $variation) {
            $variations[$variation->id] = $variation->date_display;
        }

        return $variations;
    }

    public function usesVariations()
    {
        return $this->type == self::$_TYPE_LIVE_WEBINAR;
    }

    public function isInUserMembership($user, $variation = null) {
        if (!$user) {
            return false;
        }
        return $user->isProductInMembership($this, $variation);
    }

    public function isInUserLibrary($user) {
        if (!$user) {
            return false;
        }
        return $user->isProductInLibrary($this);
    }

    public function hasOtherVariationsAssignedToMember($user, $variation) {
        if (!$user) {
            return false;
        }

        return $user->hasOtherVariationsAssignedToProduct($this, $variation);
    }

    /**
     * Eloquent Based Helpers
     */

    public function previewResources()
    {
        return $this->resources->filter(function($resource) {
            return $resource->isActive() && $resource->isPreview();
        });
    }

    public function previewResourceVideo()
    {
        return $this->resources->filter(function($resource) {
            return $resource->isActive() && $resource->isPreview() && $resource->type == Resource::$_TYPE_VIDEO;
        })->first();
    }

    public function previewResourceImage()
    {
        return $this->resources->filter(function($resource) {
            return $resource->isActive() && $resource->isPreview() && $resource->type == Resource::$_TYPE_IMAGE;
        })->first();
    }

    /*
     * Model Relations
     */

    public function author()
    {
        return $this->belongsTo('Author');
    }

    public function categories()
    {
        return $this->belongsToMany('Category')->withTimestamps();
    }

    public function orderItems()
    {
        return $this->hasMany('OrderItem');
    }

    public function resources()
    {
        return $this->morphMany('Resource', 'resourceable');
    }

    public function users()
    {
        return $this->belongsToMany('User')->withPivot(['active', 'source'])->withTimestamps();
    }

    public function membershipLevels()
    {
        return $this->belongsToMany('MembershipLevel')->orderBy('monthlyPrice')->orderBy('quarterlyPrice')->orderBy('annualPrice')->withTimestamps();
    }

    public function variations()
    {
        return $this->hasMany('Variation');
    }

    public function webform()
    {
        return $this->belongsTo('Webform');
    }
}
