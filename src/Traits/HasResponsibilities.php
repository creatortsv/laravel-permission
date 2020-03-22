<?php

namespace Spatie\Permission\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use UnexpectedValueException;

/**
 * Trait HasResponsibilities
 * @package Spatie\Permission\Traits
 */
trait HasResponsibilities
{
    /**
     * Example post_responsibilities
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (!method_exists($this, $method) && ($class = $this->getResponsibilityClass($method)) !== false) {
            return $this->getResponsibilityRelationShip($class);
        }

        return parent::__call($method, $parameters);
    }

    /**
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        /** TODO: Get relation attribute */

        return parent::__get($name);
    }

    /**
     * @param Model|Model[]|Collection $models
     * @param string $guard
     * @param string|\Spatie\Permission\Contracts\Role|null $role
     * @param string|\Spatie\Permission\Contracts\Permission|null $permission
     * @return self
     */
    public function giveResponsibilityTo($models, string $guard = 'web', $role = null, $permission = null): self
    {
        if ($role !== null) {
            $role = $role instanceof Role ? $role : Role::findByName($role, $guard);
            if (!method_exists($this, 'hasRole')) {
                throw new UnexpectedValueException('Cannot assign role to this model');
            }

            if ($role !== null && !$this->hasRole($role, $guard)) {
                $this->assignRole([$role]);
            }
        }

        if ($permission !== null) {
            $permission = $permission instanceof Permission ? $permission : Permission::findByName($permission, $guard);
            if (!method_exists($this, 'hasPermissionTo')) {
                throw new UnexpectedValueException('Cannot give permission to this model');
            }

            if ($permission !== null && !$this->hasPermissionTo($permission, $guard)) {
                $this->givePermissionTo([$permission]);
            }
        }

        collect(!is_array($models) ? [$models] : $models)
            ->flatten()
            ->each(function (Model $model) use ($role, $permission): void {
                $this->getResponsibilityRelationShip(get_class($model))
                    ->wherePivot('role_id', (int)($role ?: new Role)->id)
                    ->wherePivot('permission_id', (int)($permission ?: new Permission)->id)
                    ->syncWithoutDetaching([$model->id => [
                        'model_type' => get_class($this),
                        'entity_model_type' => get_class($model),
                        'role_id' => (int)($role ? $role->id : null),
                        'permission_id' => (int)($permission ? $permission->id : null),
                    ]]);
            });

        return $this;
    }

    /**
     * @param Model|Model[]|Collection $models
     * @param string $guard
     * @param string|\Spatie\Permission\Contracts\Role|null $role
     * @param string|\Spatie\Permission\Contracts\Permission|null $permission
     * @return HasResponsibilities
     */
    public function revokeResponsibilityTo($models, string $guard = 'web', $role = null, $permission = null): self
    {
        if ($role !== null && !($role instanceof Role)) {
            $role = Role::findByName($role, $guard);
        }

        if ($permission !== null && !($permission instanceof Permission)) {
            $permission = Permission::findByName($permission, $guard);
        }

        collect(!is_array($models) ? [$models] : $models)
            ->flatten()
            ->each(function (Model $model) use ($role, $permission): void {
                $this->getResponsibilityRelationShip(get_class($model))
                    ->wherePivot('role_id', (int)($role ?: new Role)->id)
                    ->wherePivot('permission_id', (int)($permission ?: new Permission)->id)
                    ->detach([$model->id]);
            });

        return $this;
    }

    /**
     * @param Model $model
     * @param string $guard
     * @param string|\Spatie\Permission\Contracts\Role|null $role
     * @param string|\Spatie\Permission\Contracts\Permission|null $permission
     * @return bool
     */
    public function hasResponsibility(Model $model, string $guard = 'web', $role = null, $permission = null): bool
    {
        if ($role !== null && !($role instanceof Role)) {
            $role = Role::findByName($role, $guard)->id;
        }

        if ($permission !== null && !($permission instanceof Permission)) {
            $permission = Permission::findByName($permission, $guard)->id;
        }

        return $this
            ->getResponsibilityRelationShip(get_class($model))
            ->wherePivot('role_id', (int)$role)
            ->wherePivot('permission_id', (int)$permission)
            ->first() !== null;
    }

    /**
     * @param string|Role $role
     * @return HasResponsibilities
     */
    public function revokeResponsibilitiesByRole($role): self
    {
        if (!($role instanceof Role) && ($role = Role::findByName($role)) !== null) {
            DB::table(config('permission.table_names.model_has_responsibilities'))
                ->where('role_id', $role->id)
                ->where(config('permission.column_names.model_morph_key'), $this->id)
                ->where('model_type', get_class($this))
                ->delete();
        }

        return $this;
    }

    /**
     * @param string|Permission $permission
     * @return HasResponsibilities
     */
    public function revokeResponsibilitiesByPermission($permission): self
    {
        if (!($permission instanceof Permission) && ($permission = Permission::findByName($permission)) !== null) {
            DB::table(config('permission.table_names.model_has_responsibilities'))
                ->where('permission_id', $permission->id)
                ->where(config('permission.column_names.model_morph_key'), $this->id)
                ->where('model_type', get_class($this))
                ->delete();
        }

        return $this;
    }

    /**
     * @param string $method
     * @return bool|string
     */
    private function getResponsibilityClass(string $method)
    {
        if (($position = strrpos($method, config('permission.method_names.method_responsibilities_name'))) !== false) {
            $baseClass = Str::ucfirst(Str::camel(substr($method, 0, $position)));
            foreach (config('permission.models.namespaces') as $namespace) {
                if (class_exists($class = $namespace . '\\' . $baseClass)) {
                    return $class;
                }
            }
        }

        return false;
    }

    /**
     * @param string $targetClass
     * @return BelongsToMany
     */
    private function getResponsibilityRelationShip(string $targetClass): BelongsToMany
    {
        return $this->belongsToMany(
            $targetClass,
            config('permission.table_names.model_has_responsibilities'),
            config('permission.column_names.model_morph_key'),
            config('permission.column_names.entity_morph_key')
        )
            ->withPivot(['role_id', 'permission_id'])
            ->wherePivot('model_type', get_class($this))
            ->wherePivot('entity_model_type', $targetClass);
    }
}
