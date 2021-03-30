<?php

namespace Stock\Import;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Stock\Dto\Car as CarDto;
use App\Models\CarStock\Car;
use App\Models\CarStock\CarModel;

class CarsImport
{
    private Car $builder;
    private Collection $newItems;
    private CarModel $carModel;

    public function __construct(Car $builder, CarModel $carModel)
    {
        $this->builder = $builder;
        $this->newItems = collect();
        $this->carModel = $carModel;
    }

    public function handle(Collection $cars): void
    {
        $this->removeCars($cars);

        /** @var CarDto $carDto */
        foreach ($cars as $carDto) {
            $car = $this->getCar($carDto->vin);

            if (is_null($car)) {
                try {
                    $newCar = $this->createCar($carDto);
                    $this->newItems->push($newCar);
                } catch (Exception $exception) {
                    dd($exception->getMessage());
                }

                continue;
            }

            try {
                $this->updateCar($car, $carDto);
            } catch (Exception) {
            }
        }
    }

    public function getLastImportedItems(): Collection
    {
        return $this->newItems;
    }

    protected function removeCars(Collection $cars): void
    {
        $existsVins = $this->newQuery()->pluck('vin');
        $importVins = $cars->pluck('vin');

        $toRemove = $existsVins->diff($importVins);

        if ($toRemove->isNotEmpty()) {
            $this->newQuery()->whereIn('vin', $toRemove->toArray())->delete();
        }
    }

    protected function getCar(string $vin): ?Car
    {
        return $this->newQuery()->firstWhere('vin', $vin);
    }

    protected function createCar(CarDto $carDto): Car
    {
        $model = $this->resolveModel($carDto->brand, $carDto->model) ?? throw new Exception('Модель не найдена');
        $attrs = array_merge($carDto->toArray(), [
            'model_id' => $model->id,
        ]);

        /** @var Car $car */
        $car = $this->newQuery()->create($attrs);

        foreach ($carDto->images as $image) {
            $car->images()->create(compact('image'));
        }

        return $car;
    }

    protected function updateCar(Car $car, CarDto $carDto): void
    {
        $model = $this->resolveModel($carDto->brand, $carDto->model) ?? throw new Exception('Модель не найдена');
        $attrs = array_merge($carDto->toArray(), [
            'model_id' => $model->id,
        ]);

        $car->fill($attrs);
        $car->save();

        if ($carDto->images !== $car->images->toArray()) {
            $car->images()->delete();

            foreach ($carDto->images as $image) {
                $car->images()->create(compact('image'));
            }
        }
    }

    protected function resolveModel(string $brand, string $model): ?CarModel
    {
        return $this->carModel
            ->newQuery()
            ->whereHas('brand', fn(Builder $builder) => $builder->where('name', $brand))
            ->where('name', $model)
            ->first();
    }

    protected function newQuery(): Builder
    {
        return $this->builder->newQuery();
    }
}