<?php

namespace Fjord\Crud\Repositories;

use Fjord\Crud\CrudValidator;
use Fjord\Crud\Fields\ListField\ListField;
use Fjord\Crud\Models\FormListItem;
use Fjord\Crud\Requests\CrudReadRequest;
use Fjord\Crud\Requests\CrudUpdateRequest;
use Illuminate\Http\Request;

class ListRepository extends BaseFieldRepository
{
    /**
     * Create new ListRepository instance.
     */
    public function __construct($config, $controller, $form, ListField $field)
    {
        parent::__construct($config, $controller, $form, $field);
    }

    /**
     * Load list items for model.
     *
     * @param CrudReadRequest $request
     * @param mixed           $model
     *
     * @return CrudJs
     */
    public function index(CrudReadRequest $request, $model)
    {
        return crud(
            $this->field->getRelationQuery($model)->getFlat()
        );
    }

    /**
     * Update list item.
     *
     * @param CrudUpdateRequest $request
     * @param mixed             $model
     * @param object            $payload
     *
     * @return CrudJs
     */
    public function update(CrudUpdateRequest $request, $model, $payload)
    {
        CrudValidator::validate(
            (array) $payload,
            $this->field->form,
            CrudValidator::UPDATE
        );

        $attributes = $this->formatAttributes((array) $payload, $this->field->getRegisteredFields());

        $listItem = $this->getListItem($model, $request->list_item_id);

        $listItem->update($attributes);

        return crud($listItem);
    }

    /**
     * Send new list item model.
     *
     * @param CrudUpdateRequest $request
     * @param mixed             $model
     * @param object            $payload
     *
     * @return CrudJs
     */
    public function create(CrudUpdateRequest $request, $model, $payload)
    {
        $parent = $this->getParent($model, $payload->parent_id ?? 0);

        $newDepth = ($parent->depth ?? 0) + 1;
        $this->checkMaxDepth($newDepth, $this->field->maxDepth);

        $listItem = new FormListItem([
            'parent_id'   => $parent->id ?? 0,
            'config_type' => get_class($this->config->getConfig()),
            'form_type'   => $request->form_type ?? 'show',
        ]);

        return crud($listItem);
    }

    /**
     * Store new list item to database.
     *
     * @param CrudUpdateRequest $request
     * @param mixed             $model
     * @param object            $payload
     *
     * @return CrudJs
     */
    public function store(CrudUpdateRequest $request, $model, $payload)
    {
        $parent = $this->getParent($model, $request->parent_id ?? 0);

        if ($request->parent_id && ! $parent) {
            abort(404);
        }

        if ($parent) {
            $this->checkMaxDepth($parent->depth + 1, $this->field->maxDepth);
        }

        CrudValidator::validate(
            (array) $payload,
            $this->field->form,
            CrudValidator::CREATION
        );

        $order_column = FormListItem::where([
            'config_type' => $this->config->getType(),
            'form_type'   => $payload->form_type ?? 'show',
            'model_type'  => get_class($model),
            'model_id'    => $model->id,
            'field_id'    => $this->field->id,
            'parent_id'   => $parent->id ?? 0,
        ])->count();

        $listItem = new FormListItem();
        $listItem->model_type = get_class($model);
        $listItem->model_id = $model->id;
        $listItem->field_id = $this->field->id;
        $listItem->config_type = get_class($this->config->getConfig());
        $listItem->form_type = $payload->form_type ?? 'show';
        $listItem->parent_id = $parent->id ?? 0;
        $listItem->order_column = $order_column;
        $listItem->value = $payload;
        $listItem->save();

        return crud($listItem);
    }

    /**
     * Destory list item.
     *
     * @param CrudReadRequest $request
     *
     * @return void
     */
    public function destroy(CrudUpdateRequest $request, $model, $payload)
    {
        $this->getListItem($model, $payload->list_item_id ?? 0)->delete();
    }

    /**
     * Order list.
     *
     * @param CrudUpdateRequest $request
     * @param mixed             $model
     * @param object            $payload
     *
     * @return void
     */
    public function order(CrudUpdateRequest $request, $model, $payload)
    {
        $request->validate([
            'payload.items'                => 'required',
            'payload.items.*.order_column' => 'required|integer',
            'payload.items.*.id'           => 'required|integer',
            'payload.items.*.parent_id'    => 'integer',
        ], __f('validation'));

        $orderedItems = $payload->items;
        $listItems = $this->field->getRelationQuery($model)->getFlat();

        foreach ($orderedItems as $orderedItem) {
            $parentId = $orderedItem['parent_id'] ?? null;

            if (! $parentId) {
                continue;
            }

            if (! $parent = $listItems->find($parentId)) {
                abort(405);
            }

            $this->checkMaxDepth($parent->depth + 1, $this->field->maxDepth);
        }

        foreach ($orderedItems as $orderedItem) {
            $update = [
                'order_column' => $orderedItem['order_column'],
            ];
            if (array_key_exists('parent_id', $orderedItem)) {
                $update['parent_id'] = $orderedItem['parent_id'];
            }
            $this->field->getRelationQuery($model)
                ->where('id', $orderedItem['id'])
                ->update($update);
        }
    }

    /**
     * Get child field.
     *
     * @param string $field_id
     *
     * @return Field|null
     */
    public function getField($field_id)
    {
        return $this->field->form->findField($field_id);
    }

    /**
     * Get list item model.
     *
     * @param Request $request
     * @param mixed   $model
     *
     * @return ListItem
     */
    public function getModel(Request $request, $model)
    {
        return $this->getListItem($model, $request->list_item_id);
    }

    /**
     * Get list item.
     *
     * @param string|int $id
     *
     * @return ListItem
     */
    protected function getListItem($model, $id)
    {
        return $model->{$this->field->id}()->findOrFail($id);
    }

    /**
     * Get parent by id.
     *
     * @param string|int $parentId
     *
     * @return FormListItem
     */
    protected function getParent($model, $parentId = 0)
    {
        return $this->field->getRelationQuery($model)
            ->getFlat()
            ->find($parentId);
    }

    /**
     * Check max depth.
     *
     * @param int $depth
     * @param int $maxDepth
     *
     * @return void
     */
    protected function checkMaxDepth(int $depth, int $maxDepth)
    {
        if ($depth <= $maxDepth) {
            return;
        }

        return abort(405, __f('crud.fields.list.messages.max_depth', [
            'count' => $maxDepth,
        ]));
    }
}