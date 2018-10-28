<?php
namespace Couchdb\Test\Traits;

trait VisibilityTrait
{
    /**
     * Set private property to an object
     *
     * @param object $object
     * @param string $property
     * @param mixed $value
     */
    protected function setPrivateProperty($object, $property, $value)
    {
        \Closure::bind(function ($object, $value) use ($property) {
            $object->$property = $value;
        }, null, $object)->__invoke($object, $value);
    }

    /**
     * Get private property from an object
     *
     * @param object $object
     * @param string $property
     *
     * @return mixed
     */
    protected function getPrivateProperty($object, $property)
    {
        return \Closure::bind(function ($object) use ($property) {
            return $object->$property;
        }, null, $object)->__invoke($object);
    }
}