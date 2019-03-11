# Swagger/openapi 3 docs generator.

### Simpler way to convert PSR-2 Docblock to openapi 3 json file. 

**To Use it with Lumen/Laravel, please do the following:**
* Register repo with composer.json by adding it to the `respositories` array, i.e.
~~~~~~~~~~
    "repositories": [
        {
            "type": "vcs",
            "url": "ssh://git@github.com:jcuna/api_docs.git"
        }
~~~~~~~~~~
* Add `"jcuna/api_docs": "*"` package to require object in composer.json i.e `"require": {"jcuna/api_docs": "*"}`
* Register the provider with the Lumen/Laravel app on `bootstrap/app.php` add `$app->register(Jcuna\ApiDocs\ServiceProvider::class);`
* Copy the example `vendor/jcuna/api_docs/src/config/jcuna/swagger.php` config file into `config/jcuna/swagger.php` and modify it accordingly
* Write PSR-2 compliant docblock above methods. i.e.

~~~~~~~~~~~~~~~~~~~~~~~

/**
 * This is a general description for the endpoint
 *
 * @param Request $request
 * [string $start required start date, string $end end date]
 * @return \Illuminate\Http\JsonResponse
 * [integer $id The user ID, string $username The user name] A User object @code 200
 *
 * @throws \Exception an error occurred @code 500
 *
 */
~~~~~~~~~~~~~~~~~~~~~~~ 

* Currently the `@return` tag needs to return a JsonResponse so that the api is docummented as such, other types might be added later.
* `@code` tag can be added in any throws or return tag to describe the httpd response code
* Descriptors are defined after the main param type is defined, i.e. all http request params go inside brackets after the `Request $request` object, same for responses
* Responses can be an array of properties or models, just wrap them around extra brackets. i.e. `[[string date, ModelClass]]`
* Support for models was built into it, however atm it wil not generate the models definitions in the schemas property, once we decide how models will work we can easily provide this functionality.
* Models can be used as params but also as responses. Once full support is built, defining models will be as follows:

~~~~~~~~~~~~~~~~~~~~~~~

/**
 * This is a general description for the endpoint
 *
 * @param Request $request [ModelClass]
 * @return \Illuminate\Http\JsonResponse
 * [[ModelClass]] an array of ModelClass @code 200
 *
 * @throws \Exception Failed validation
 * @throws SqlException an unexpected error occurred @code 405
 *
 */
~~~~~~~~~~~~~~~~~~~~~~~ 

Once you're ready to generate the file, run `php artisan api:docs`
