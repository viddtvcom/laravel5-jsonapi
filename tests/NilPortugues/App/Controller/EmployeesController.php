<?php
/**
 * Author: Nil Portugués Calderó <contact@nilportugues.com>
 * Date: 12/9/15
 * Time: 2:57 PM.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NilPortugues\Tests\App\Controller;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use NilPortugues\Api\JsonApi\Server\Actions\ListResource;
use NilPortugues\Api\JsonApi\Server\Errors\Error;
use NilPortugues\Api\JsonApi\Server\Errors\ErrorBag;
use NilPortugues\Laravel5\JsonApi\Controller\JsonApiController;
use NilPortugues\Laravel5\JsonApi\Eloquent\EloquentHelper;
use NilPortugues\Tests\App\Models\Employees;
use NilPortugues\Tests\App\Models\Orders;

/**
 * Class EmployeeController.
 */
class EmployeesController extends JsonApiController
{
    /**
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getDataModel()
    {
        return new Employees();
    }

    /**
     * @return callable
     */
    protected function createResourceCallable()
    {
        $createOrderResource = function (Model $model, array $data) {
            if (!empty($data['relationships']['order']['data'])) {
                $orderData = $data['relationships']['order']['data'];

                if (!empty($orderData['type'])) {
                    $orderData = [$orderData];
                }

                foreach ($orderData as $order) {
                    $attributes = array_merge($order['attributes'], ['employee_id' => $model->getKey()]);
                    Orders::create($attributes);
                }
            }
        };

        return function (array $data, array $values, ErrorBag $errorBag) use ($createOrderResource) {

            $attributes = [];
            foreach ($values as $name => $value) {
                $attributes[$name] = $value;
            }

            if (!empty($data['id'])) {
                $attributes[$this->getDataModel()->getKeyName()] = $values['id'];
            }

            DB::beginTransaction();
            try {
                $model = $this->getDataModel()->create($attributes);
                $createOrderResource($model, $data);
                DB::commit();

                return $model;
            } catch (\Exception $e) {
                DB::rollback();
                $errorBag[] = new Error('creation_error', 'Resource could not be created');
                throw new \Exception();
            }

        };
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getOrdersByEmployee(Request $request)
    {
        $resource = new ListResource($this->serializer);

        $totalAmount = function () use ($request) {
            $id = (new Orders())->getKeyName();

            return Orders::query()->where('employee_id', '=', $request->employee_id)->get([$id])->count();
        };

        $results = function () use ($request) {
            return EloquentHelper::paginate(
                $this->serializer,
                Orders::query()->where('employee_id', '=', $request->employee_id)
            )->get();
        };

        $uri = route('employees.orders', ['employee_id' => $request->employee_id]);

        return $resource->get($totalAmount, $results, $uri, Orders::class);
    }
}