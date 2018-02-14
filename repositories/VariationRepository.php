<?php
namespace Charity\Howto\Repositories;

use Carbon\Carbon;
use DB;
use Product;
use Variation;

class VariationRepository extends EloquentRepository {

    public function __construct(\Variation $model)
    {
        $this->model = $model;
    }

    public function getPresentationsClosingSoon()
    {
        return $this->model
            ->where('date', '<=', Carbon::now()->addHours(48))
            ->whereNotIn('attendance_status', [ Variation::$_ATTENDANCE_STATUS_SOLD_OUT, Variation::$_ATTENDANCE_STATUS_CLOSING_SOON, Variation::$_ATTENDANCE_STATUS_ALMOST_SOLD_OUT ])
            ->get();
    }

    public function getPresentationsAlmostSoldOutCandidates()
    {
        return $this->model
            ->where('date', '>=', Carbon::now())
            ->whereNotIn('attendance_status', [Variation::$_ATTENDANCE_STATUS_SOLD_OUT, Variation::$_ATTENDANCE_STATUS_ALMOST_SOLD_OUT])
            ->whereHas('product', function($q) {
                $q->where('price', '>', 0);
            })
            ->has('UserVariations', '>=', 15)
            ->get();
    }

    public function getAllForDay($date = null)
    {
        $startDate = $date ? Carbon::parse($date) : Carbon::now();

        return $this->model
            ->whereBetween('date', [$startDate->setTime(0, 0)->format('Y-m-d H:i:s'), $startDate->addDay()->format('Y-m-d H:i:s')])
            ->where('status', Variation::$_STATUS_ACTIVE)
            ->orderBy('date')
            ->get();
    }

    /**
     * Get active variations by id
     *
     * @param $id
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getActiveById($id)
    {
        return $this->model
            ->where('id', $id)
            ->where('status', Variation::$_STATUS_ACTIVE)
            ->orderBy('date')
            ->get();
    }


    public function getUpcomingForBonusNotification()
    {
        $variations = $this->model
            ->whereBetween('date', [Carbon::now()->addHours(2), Carbon::now()->addHours(3)])
            ->whereNull('bonus_notification')
            ->where('send_notifications', true)
            ->whereNotIn('status', [Variation::$_STATUS_INACTIVE, Variation::$_STATUS_CANCELLED_GLOBALLY])
            ->get();

        return $variations;
    }

    public function getUpcomingForRecordingNotification()
    {
        $variations = $this->model
            ->whereBetween('date', [Carbon::now()->subDay()->setTime(0, 0), Carbon::now()->setTime(0, 0)])
            ->whereNull('recording_notification')
            ->where('send_notifications', true)
            ->where('status', '!=', Variation::$_STATUS_INACTIVE)
            ->get();

        $log = DB::getQueryLog();

        return $variations;
    }

    /**
     * Find upcoming presentations
     * @param null $limit
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function findUpcoming($limit = null)
    {
        $query = $this->model
            ->where('date', '>=', Carbon::now()->addMinutes(5))
            ->where('status',  Variation::$_STATUS_ACTIVE)
            ->whereHas('product', function($q) {
                $q->whereNotIn('status', [Product::$_STATUS_INACTIVE, Product::$_STATUS_IMPORTED]);
            })
            ->orderBy('date');

        return $limit ? $query->limit($limit)->get() : $query->get();
    }

    /**
     * Find upcoming presentations in a specific category
     * @param $categoryId
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function findUpcomingByCategory($categoryId)
    {
        return $this->model
            ->where('date', '>=', Carbon::now()->addMinutes(5))
            ->where('status', Variation::$_STATUS_ACTIVE)
            ->whereHas('product', function ($query) use ($categoryId) {
                $query
                    ->whereNotIn('status', [Product::$_STATUS_INACTIVE, Product::$_STATUS_IMPORTED])
                    ->whereHas('categories', function ($query) use ($categoryId) {
                        $query->where('category_id', $categoryId);
                    });
            })
            ->orderBy('date')
            ->get();
    }
    /**
     * Find upcoming presentations with CFRE Approved
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function findUpcomingCfre()
    {
        $query = $this->model
            ->where('date', '>=', Carbon::now()->addMinutes(5))
            ->where('status',  Variation::$_STATUS_ACTIVE)
            ->whereHas('product', function($q) {
                $q->whereNotIn('status', [Product::$_STATUS_INACTIVE, Product::$_STATUS_IMPORTED])
                ->where('approved_cfre', '=', 1)
                ->where('credits_cfre', '>', 0);
            })
            ->orderBy('date');
        return $query->get();
    }
    /**
     * Find upcoming presentations top rated
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function findUpcomingTopRated()
    {
        $query = $this->model
            ->where('date', '>=', Carbon::now()->addMinutes(5))
            ->where('status',  Variation::$_STATUS_ACTIVE)
            ->whereHas('product', function($q) {
                $q->whereNotIn('status', [Product::$_STATUS_INACTIVE, Product::$_STATUS_IMPORTED])
                ->where('top_rated', '=', 1);
            })
            ->orderBy('date');
        return $query->get();
    }
    /**
     * Find upcoming presentations CFRE Approved in a specific category
     * @param $categoryId
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function findUpcomingCfreByCategory($categoryId)
    {
        return $this->model
            ->where('date', '>=', Carbon::now()->addMinutes(5))
            ->where('status', Variation::$_STATUS_ACTIVE)
            ->whereHas('product', function ($query) use ($categoryId) {
                $query
                    ->whereNotIn('status', [Product::$_STATUS_INACTIVE, Product::$_STATUS_IMPORTED])
                    ->where('approved_cfre', '=', 1)
                    ->where('credits_cfre', '>', 0)
                    ->whereHas('categories', function ($query) use ($categoryId) {
                        $query->where('category_id', $categoryId);
                    });
            })
            ->orderBy('date')
            ->get();
    }
    /**
     * Find upcoming top rated presentations in a specific category
     * @param $categoryId
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function findUpcomingTopRatedByCategory($categoryId)
    {
        return $this->model
            ->where('date', '>=', Carbon::now()->addMinutes(5))
            ->where('status', Variation::$_STATUS_ACTIVE)
            ->whereHas('product', function ($query) use ($categoryId) {
                $query
                    ->whereNotIn('status', [Product::$_STATUS_INACTIVE, Product::$_STATUS_IMPORTED])
                    ->where('top_rated', '=', 1)
                    ->whereHas('categories', function ($query) use ($categoryId) {
                        $query->where('category_id', $categoryId);
                    });
            })
            ->orderBy('date')
            ->get();
    }
    /**
     * Find upcoming presentations in a specific category (array) with a limit
     * @param $categoryId
     * @param $limit
     * @param $currProductsIds
     * @param $currProductsNames
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function findUpcomingByCategoryLimit($categoryId, $limit, $currProductsIds, $currProductsNames)
    {

        $upcoming = $this->model
            ->where('date', '>=', Carbon::now()->addMinutes(5))
            ->where('status', Variation::$_STATUS_ACTIVE)
            ->where('attendance_status', '!=', Variation::$_ATTENDANCE_STATUS_SOLD_OUT)
            ->whereHas('product', function ($query) use ($categoryId,$currProductsIds) {
                $query
                    ->where('price','>',0)
                    ->whereNotIn('status', [Product::$_STATUS_INACTIVE, Product::$_STATUS_IMPORTED])
                    ->whereNotIn('id', $currProductsIds)
                    ->whereHas('categories', function ($query) use ($categoryId) {
                        $query->whereIn('category_id', $categoryId);
                    });
            })
            ->take($limit)
            ->orderBy('date')
            ->get();

        $filtered = $upcoming->reject(function ($value) use ($currProductsNames){
            $product = $value->product;
            foreach ($currProductsNames as $currName) {
                if(strlen($product->name) >= 40 && strlen($currName) >= 40){
                    if (substr_compare($product->name, $currName, 0, 39, false) == 0){
                        return true;
                    }
                }
            }
            return false;
        });

        return $filtered;
    }

    public function findForAdmin()
    {
        return $this->model
            ->with(['product.resources', 'userVariations', 'resources'])
            ->where('date', '>=', Carbon::now()->subDays(7))
            ->orderBy('date')
            ->get();
    }

    public function findByDateTime($productId, $dateTime)
    {
        return $this->model->where('product_id', $productId)->where('date', $dateTime)->first();
    }

    public function findBySlug($slug)
    {
        return $this->model->where('slug', '=', $slug)->first();
    }

    public function findBySlugAndProduct($slug, $productId)
    {
        return $this->model->where('slug', '=', $slug)->where('product_id', $productId)->first();
    }
}
