<?php

namespace Venturecraft\Revisionable;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Support\Facades\Log;

/**
 * Revision.
 *
 * Base model to allow for revision history on
 * any model that extends this model
 *
 * (c) Venture Craft <http://www.venturecraft.com.au>
 */
class Revision extends Eloquent
{
    /**
     * @var string
     */
    public $table = 'revisions';

    /**
     * @var array
     */
    protected $revisionFormattedFields = array();

    /**
     * @param array $attributes
     */
    public function __construct(array $attributes = array())
    {
        parent::__construct($attributes);
    }

    /**
     * Revisionable.
     *
     * Grab the revision history for the model that is calling
     *
     * @return array revision history
     */
    public function revisionable()
    {
        return $this->morphTo();
    }

    /**
     * Field Name
     *
     * Returns the field that was updated, in the case that it's a foreign key
     * denoted by a suffix of "_id", then "_id" is simply stripped
     *
     * @return string field
     */
    public function fieldName()
    {
        if ($formatted = $this->formatFieldName($this->field)) {
            return $formatted;
        } elseif (strpos($this->field, '_id')) {
            return str_replace('_id', '', $this->field);
        } else {
            return $this->field;
        }
    }

    /**
     * Format field name.
     *
     * Allow overrides for field names.
     *
     * @param $field
     *
     * @return bool
     */
    private function formatFieldName($field)
    {
        $related_model = $this->revisionable_type;
        $related_model = new $related_model;
        $revisionFormattedFieldNames = $related_model->getRevisionFormattedFieldNames();

        if (isset($revisionFormattedFieldNames[$field])) {
            return $revisionFormattedFieldNames[$field];
        }

        return false;
    }

    /**
     * Old Value.
     *
     * Grab the old value of the field, if it was a foreign key
     * attempt to get an identifying name for the model.
     *
     * @return string old value
     */
    public function oldValue()
    {
        return $this->getValue('old');
    }


    /**
     * New Value.
     *
     * Grab the new value of the field, if it was a foreign key
     * attempt to get an identifying name for the model.
     *
     * @return string old value
     */
    public function newValue()
    {
        return $this->getValue('new');
    }


    /**
     * Responsible for actually doing the grunt work for getting the
     * old or new value for the revision.
     *
     * @param  string $which old or new
     *
     * @return string value
     */
    private function getValue($which = 'new')
    {
        $which_value = $which . '_value';

        // First find the main model that was updated
        $main_model = $this->revisionable_type;
        // Load it, WITH the related model
        if (class_exists($main_model)) {
            $main_model = new $main_model;

            try {
                // If this is a foreign key
                if (strpos($this->field, '_id')) {
                    $related_model = str_replace('_id', '', $this->field);

                    // Now we can find out the namespace of of related model
                    if (!method_exists($main_model, $related_model)) {
                        $related_model = camel_case($related_model); // for cases like published_status_id
                        if (!method_exists($main_model, $related_model)) {
                            throw new \Exception('Relation ' . $related_model . ' does not exist for ' . $main_model);
                        }
                    }
                    $related_class = $main_model->$related_model()->getRelated();

                    // Finally, now that we know the namespace of the related model
                    // we can load it, to find the information we so desire
                    $item;
                    if (array_key_exists('Illuminate\Database\Eloquent\SoftDeletes', class_uses($related_class))) {
                        $item = $related_class::withTrashed()->find($this->$which_value);
                    } else {
                        $item = $related_class::find($this->$which_value);
                    }

                    if (is_null($this->$which_value) || $this->$which_value == '') {
                        $item = new $related_class;

                        return $item->getRevisionNullString();
                    }
                    if (!$item) {
                        $item = new $related_class;

                        return $this->format($this->field, $item->getRevisionUnknownString());
                    }


                    // see if there's an available accessor (e.g. getFormatIdAttribute)
                    $accessor = 'get' . studly_case($this->field) . 'Attribute';
                    if (method_exists($item, $accessor)) {
                        return $this->format($item->$accessor($this->field), $item->identifiableName());
                    }

                    return $this->format($this->field, $item->identifiableName());
                }
            } catch (\Exception $e) {
                // Just a fail-safe, in the case the data setup isn't as expected
                // Nothing to do here.
                Log::info('Revisionable: ' . $e);
            }

            // if there was an issue
            // or, if it's a normal value

            // ashirk: We're going to call getFieldDisplayAttribute() rather than getFieldAttribute
            // because this is for display purposes. Using getFieldAttribute conflates accessing the
            // actual field value with getting value for display purposes to the end user.
            $accessor = 'get' . studly_case($this->field) . 'DisplayAttribute';
            if (method_exists($main_model, $accessor)) {
                return $this->format($this->field, $main_model->$accessor($this->$which_value));
            }
        }

        return $this->format($this->field, $this->$which_value);
    }

    /**
     * User Responsible.
     *
     * @return User user responsible for the change
     */
    public function userResponsible()
    {
        if (empty($this->user_id)) { return false; }
        if (class_exists($class = '\Cartalyst\Sentry\Facades\Laravel\Sentry')
            || class_exists($class = '\Cartalyst\Sentinel\Laravel\Facades\Sentinel')
        ) {
            return $class::findUserById($this->user_id);
        } else {
            $user_model = app('config')->get('auth.model');

            if (empty($user_model)) {
                $user_model = app('config')->get('auth.providers.users.model');
                if (empty($user_model)) {
                    return false;
                }
            }
            if (!class_exists($user_model)) {
                return false;
            }
            return $user_model::find($this->user_id);
        }
    }

    /**
     * Returns the object we have the history of
     *
     * @return Object|false
     */
    public function historyOf()
    {
        if (class_exists($class = $this->revisionable_type)) {
            return $class::find($this->revisionable_id);
        }

        return false;
    }

    /*
     * Examples:
    array(
        'public' => 'boolean:Yes|No',
        'minimum'  => 'string:Min: %s'
    )
     */
    /**
     * Format the value according to the $revisionFormattedFields array.
     *
     * @param  $field
     * @param  $value
     *
     * @return string formatted value
     */
    public function format($field, $value)
    {
        $related_model = $this->revisionable_type;
        $related_model = new $related_model;
        $revisionFormattedFields = $related_model->getRevisionFormattedFields();

        if (isset($revisionFormattedFields[$field])) {
            return FieldFormatter::format($field, $value, $revisionFormattedFields);
        } else {
            return $value;
        }
    }

}
