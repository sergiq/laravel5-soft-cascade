<?php

namespace Askedio\SoftCascade;

use Askedio\SoftCascade\Exceptions\SoftCascadeNonExistentRelationActionException;
use Askedio\SoftCascade\Exceptions\SoftCascadeRestrictedException;
use Askedio\SoftCascade\Contracts\SoftCascadeable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SoftCascade implements SoftCascadeable
{
    protected $direction;
    protected $directionData;
    protected $availableActions = ['update', 'restrict'];

    /**
     * Cascade over Eloquent items.
     *
     * @param Illuminate\Database\Eloquent\Model $models
     * @param string                             $direction update|delete|restore
     * @param array                              $directionData
     *
     * @return void
     */
    public function cascade($models, $direction, $directionData = [])
    {
        DB::beginTransaction(); //Start db transaction for rollback when error
        $this->direction = $direction;
        $this->directionData = $directionData;
        $this->run($models);
        DB::commit(); //All ok we commit all database queries
    }

    /**
     * Run the cascade.
     *
     * @param Illuminate\Database\Eloquent\Model $models
     *
     * @return void
     */
    protected function run($models)
    {
        $models = collect($models);
        if ($models->count() > 0) {
            $model = $models->first();

            if (!is_object($model)) {
                return;
            }

            if (!$this->isCascadable($model)) {
                return;
            }

            $this->relations($model, $model->getForeignKey(), $models->pluck($model->getKeyName()));
        }
        return;
    }

    /**
     * Iterate over the relations.
     *
     * @param Illuminate\Database\Eloquent\Model $model
     * @param string                             $foreignKey
     * @param array                              $foreignKeyIds
     *
     * @return mixed
     */
    protected function relations($model, $foreignKey, $foreignKeyIds)
    {
        $relations = $model->getSoftCascade();

        if (empty($relations)) {
            return;
        }

        foreach ($relations as $relation) {
            extract($this->relationResolver($relation));
            $this->validateRelation($model, $relation);
            
            $affectedRowsOnExecute = $this->affectedRowsOnExecute($model->$relation(), $foreignKey, $foreignKeyIds);

            if ($action === 'restrict' && $affectedRowsOnExecute > 0) {
                DB::rollBack(); //Rollback the transaction before throw exception
                throw (new SoftCascadeRestrictedException)->setModel(get_class($model->$relation()->getModel()), $foreignKey, $foreignKeyIds->toArray());
            }

            $this->execute($model->$relation(), $foreignKey, $foreignKeyIds, $affectedRowsOnExecute);
        }
    }

    /**
     * Execute delete, or restore.
     *
     * @param Illuminate\Database\Eloquent\Relations\Relation $relation
     * @param string                                          $foreignKey
     * @param array                                           $foreignKeyIds
     * @param int                                             $$affectedRowsOnExecute
     *
     * @return void
     */
    protected function execute($relation, $foreignKey, $foreignKeyIds, $affectedRowsOnExecute)
    {
        $relationModel = $relation->getQuery()->getModel();
        $relationModel = new $relationModel();
        if ($affectedRowsOnExecute > 0) {
            $relationModel = $relationModel->withTrashed()->whereIn($foreignKey, $foreignKeyIds)->limit($affectedRowsOnExecute);
            $this->run($relationModel->get([$relationModel->getModel()->getKeyName()]));
            $relationModel->{$this->direction}($this->directionData);
        }
    }

    /**
     * Validate the relation method exists and is a type of Eloquent Relation.
     *
     * @param Illuminate\Database\Eloquent\Model $model
     * @param string                             $relation
     *
     * @return void
     */
    protected function validateRelation($model, $relation)
    {
        $class = get_class($model);
        if (!method_exists($model, $relation)) {
            DB::rollBack(); //Rollback the transaction before throw exception
            throw new \LogicException(sprintf('%s does not have method \'%s\'.', $class, $relation));
        }

        if (!$model->$relation() instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
            DB::rollBack(); //Rollback the transaction before throw exception
            throw new \LogicException(sprintf('%s \'%s\' is not an instance of Illuminate\Database\Eloquent\Relations\Relation.', $class, $relation));
        }
    }

    /**
     * Check if the model is enabled to cascade.
     *
     * @param Illuminate\Database\Eloquent\Model $model
     *
     * @return bool
     */
    protected function isCascadable($model)
    {
        return method_exists($model, 'getSoftCascade');
    }

    /**
     * Affected rows if we do execute.
     *
     * @param Illuminate\Database\Eloquent\Relations\Relation $relation
     * @param string                                          $foreignKey
     * @param array                                           $foreignKeyIds
     *
     * @return void
     */
    protected function affectedRowsOnExecute($relation, $foreignKey, $foreignKeyIds)
    {
        $relationModel = $relation->getQuery()->getModel();
        $relationModel = new $relationModel();
        return $relationModel->withTrashed()->whereIn($foreignKey, $foreignKeyIds)->count();
    }

    /**
     * Resolve relation string
     * 
     * @param string $relation 
     * 
     * @return array
     */
    protected function relationResolver($relation)
    {
        $return = ['relation' => '', 'action' => 'update'];

        try {
            list($relation, $action) = explode('@', $relation);
            $return = ['relation' => $relation, 'action' => $action];
        } catch (\Exception $e) {
            $return['relation'] = $relation;
        }

        if (!in_array($return['action'], $this->availableActions)) {
            DB::rollBack(); //Rollback the transaction before throw exception
            throw (new SoftCascadeNonExistentRelationActionException)->setRelation(implode('@', $return));
        }

        return $return;
    }
}
