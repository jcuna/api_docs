<?php

/**
 * Created by jcuna.
 * Date: 11/26/18
 * Time: 5:25 PM
 */

declare(strict_types=1);

namespace Jcuna\ApiDocs\Cli;

use Doctrine\Common\Annotations\AnnotationReader;
use FastRoute\Route;
use http\Exception\InvalidArgumentException;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\BaseTag;

class ApiDocs extends Command
{

    /**
     * @var string
     */
    protected $signature = 'api:docs';

    /**
     * @var DocBlockFactory
     */
    private $docFactory;

    /** @var array */
    const URL_ENCODE_TEMPLATE = [
        'required' => true,
        'content' => [
            'application/x-www-form-urlencoded' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => []
                ]
            ]
        ]
    ];

    /** @var array */
    const JSON_SCHEMA_TEMPLATE = [
        'content' => [
            'application/json' => [
                'schema' => [
                    '$ref' => ''
                ]
            ]
        ]
    ];

    /** @var array */
    private static $addedModels = [];

    /** @var array */
    private static $addedSecurity = [];

    /** @var array */
    const METHOD_SECURITY_SCOPE_MAP = [
        'GET' => 'read',
        'POST' => 'write',
        'PUT' => 'update',
        'DELETE' => 'delete'
    ];

    public function handle(): void
    {
        $configs = config('jcuna/swagger');
        $this->docFactory = DocBlockFactory::createInstance();

        $routes = app()->router->getRoutes();

        foreach ($configs as $path => $config) {
            self::$addedModels = [];
            $this->followTheRoutes($routes, $path, $config);
        }
    }

    /**
     * @param array $routes
     * @param string $path
     * @param array $config
     * @throws \ReflectionException
     */
    private function followTheRoutes(array $routes, string $path, array $config): void
    {
        $endponts = self::init($config);
        foreach ($routes as $route) {
            # if uri not for this config, skip
            if (strpos($route['uri'], $path) === false) {
                continue;
            }

            list($controller, $method) = explode('@', $route['action']['uses']);

            if (!class_exists($controller)) {
                continue;
            }

            $reflectionClass = new \ReflectionClass($controller);

            if (!$reflectionClass->hasMethod($method)) {
                continue;
            }

            $reflectionMethod = $reflectionClass->getMethod($method);

            try {
                $docblock = $this->docFactory->create($reflectionMethod);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $parameters = $this->getParameters($docblock, $route, $reflectionClass);

            $paramDocs = [
                'operationId' => str_replace('@', '::', $route['action']['uses'])
            ];

            if (!empty($parameters)) {
                $paramDocs = array_merge($paramDocs, $parameters);
            }

            $paramDocs = array_merge($paramDocs, [
                'responses' => $this->getResponses($docblock, $reflectionClass),
                'security' => $this->getSecurity($route),
            ]);

            $endponts['paths'][$route['uri']][strtolower($route['method'])] = $paramDocs;

            $components = $this->getComponents($config);
            if (!empty($components)) {
                $endponts['components'] = $components;
            }

            $this->writeFile($endponts, $config);
        }
    }

    /**
     * @param string $description
     * @param bool|null $isArray
     * @return DocBlock|null
     */
    private function parseAdditionalDescription(string $description, ?bool &$isArray): ?DocBlock
    {
        $isArray = false;
        preg_match('#\[(.*)\]#', $description, $matchedArray);

        if (empty($matchedArray)) {
            return null;
        } else {
            if (strpos($matchedArray[1], '[') === 0 &&
                strpos($matchedArray[1], ']') === mb_strlen($matchedArray[1]) - 1) {
                $isArray = true;
            }
            $params = explode(',', trim($matchedArray[1], '[]'));
        }

        $summary = trim(str_replace($matchedArray[0], '', $description));

        $paramTags = '/**' . PHP_EOL;
        if (!empty($summary)) {
            $paramTags .= '* ' . $summary . PHP_EOL;
        }
        foreach ($params as $param) {
            $paramTags .= '* @param ' . trim($param) . PHP_EOL;
        }
        $paramTags .= '* @return void' . PHP_EOL;
        $paramTags .= '*/';

        try {
            return $this->docFactory->create($paramTags);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * @param DocBlock $docblock
     * @return array
     */
    private function getParameters(DocBlock $docblock, array $route, \ReflectionClass $reflectionClass): array
    {
        $parameters = [];
        $response = [];
        $method = $route['method'];
        /** @var phpDocumentor\Reflection\DocBlock\Tags\BaseTag $paramTags */
        $description = $docblock->getSummary();

        if (!empty($description)) {
            $response['description'] = $description;
        }

        foreach ($docblock->getTagsByName('param') as $tag) {
            if ($tag instanceof Param) {
                $params = $this->parseAdditionalDescription((string) $tag->getDescription(), $isArray);
                if (is_null($params)) {
                    continue;
                }

                if ($isArray) {
                    throw new \InvalidArgumentException('Input params as array is not currently supported');
                }

                foreach ($this->buildParams(
                    $method,
                    $reflectionClass,
                    ...$params->getTagsByName('param')
                ) as $param) {
                    $parameters[] = $param;
                }
            }
        }

        if (empty($parameters)) {
            return [];
        }

        if ($method === 'GET') {
            $response['parameters'] = $parameters;
        } else {
            $response['requestBody'] = $parameters[0];
        }

        return $response;
    }

    /**
     * @param string $method
     * @param Basetag ...$params
     * @return \Generator
     */
    private function buildParams(string $method, \ReflectionClass $reflection, Basetag ...$params): \Generator
    {
        if ($method !== 'GET') {
            return $this->bodyParams($reflection, ...$params);
        }

        return $this->inQueryParams(...$params);
    }

    /**
     * @param Basetag ...$params
     * @return \Generator
     */
    private function inQueryParams(Basetag ...$params): \Generator
    {
        foreach ($params as $param) {
            if ($param instanceof Param) {
                $yield = [
                    'name' => $param->getVariableName(),
                    'in' => 'query',
                    'required' => strpos((string) $param->getDescription(), 'required ') !== false,
                    'schema' => ['type' => (string) $param->getType()]
                ];
                $description = str_replace('required', '', (string) $param->getDescription());
                if (!empty($description)) {
                    $yield['description'] = trim($description);
                };

                yield $yield;
            }
        }
    }

    /**
     * @param Param $param
     * @return array
     */
    private function buildParamProperty(Param $param): array
    {
        $property = [];

        $property[$param->getVariableName()] = [
            'type' => ((string) $param->getType()) === 'int' ? 'integer' : (string) $param->getType()
        ];

        $description = str_replace('required', '', (string) $param->getDescription());
        if (!empty($description)) {
            $property[$param->getVariableName()]['description'] = trim($description);
        };

        return $property;
    }

    /**
     * @param Param $param
     * @return bool
     */
    private function isRequired(Param $param): bool
    {
        return (strpos((string) $param->getDescription(), 'required') !== false);
    }

    /**
     * @param Param $param
     * @param \ReflectionClass $reflectionClass
     * @return string
     */
    private function buildParamModel(Param $param, \ReflectionClass $reflectionClass): string
    {
        $reader = file_get_contents($reflectionClass->getFileName());
        $model = "#/components/schemas/{$param->getType()->getFqsen()->getName()}";
        preg_match('/use\s+(.*\\' . $param->getType() . ')/i', $reader, $matches);
        if (!empty($matches) && isset($matches[1])) {
            self::$addedModels[$model] = $matches[1];
        }
        return $model;
    }

    /**
     * @param \ReflectionClass $reflectionClass
     * @param BaseTag ...$params
     * @return \Generator
     */
    private function bodyParams(\ReflectionClass $reflectionClass, Basetag ...$params): \Generator
    {
        $properties = [];
        $requiredProperties = [];
        $model = [];
        $definitions = [];

        foreach ($params as $param) {
            if ($param instanceof Param) {
                if ($this->isPrimitiveType((string) $param->getType())) {
                    if ($this->isRequired($param)) {
                        $requiredProperties[] = $param->getVariableName();
                    }
                    $properties = array_merge($properties, $this->buildParamProperty($param));
                } else {
                    $model = $this->buildParamModel($param, $reflectionClass);
                }
            }
        }

        if (!empty($properties)) {
            if (!empty($requiredProperties)) {
                $definitions = $this->updateDeepValue(
                    self::URL_ENCODE_TEMPLATE,
                    'schema',
                    [
                        'type' => 'object',
                        'properties' => [],
                        'required' => $requiredProperties
                    ]
                );
            }
            $definitions = $this->updateDeepValue(
                $definitions,
                'properties',
                $properties
            );
        }

        if (!empty($model)) {
            $definitions = array_merge($definitions, $this->updateDeepValue(
                self::JSON_SCHEMA_TEMPLATE,
                '$ref',
                $model
            ));
        }

        yield $definitions;
    }

    /**
     * @param string $desc
     * @param int $defaultCode
     * @return string
     */
    private function getResponseCode(string &$desc, int $defaultCode = 200): string
    {
        preg_match('#@code +(\d+)#', $desc, $matchedCode);

        $code = (string) $defaultCode;
        if (!empty($matchedCode)) {
            $desc = trim(str_replace($matchedCode[0], '', $desc));
            $code = $matchedCode[1];
        }

        return $code;
    }

    /**
     * @param DocBlock $docblock
     * @param \ReflectionClass $reflectionClass
     * @return array
     */
    private function getResponses(DocBlock $docblock, \ReflectionClass $reflectionClass): array
    {
        $responses = [];

        /** @var DocBlock\Tags\Return_ $return */
        foreach ($docblock->getTagsByName('return') as $return) {
            $desc = trim((string) $return->getDescription());
            $code = $this->getResponseCode($desc);
            $models = [];
            $properties = [];
            $thisOutput = [
                'description' => $desc
            ];

            $returnValues = $this->parseAdditionalDescription($desc, $isArray);

            if (!is_null($returnValues)) {
                $thisOutput['description'] = (string) $returnValues->getSummary();
                /** @var Param $param */
                foreach ($returnValues->getTagsByName('param') as $param) {
                    if ($this->isPrimitiveType((string) $param->getType())) {
                        $properties = array_merge($properties, $this->buildParamProperty($param));
                    } else {
                        $models[] = ['$ref' => $this->buildParamModel($param, $reflectionClass)];
                    }
                }
            }

            if (count($models) > 1 && !$isArray) {
                throw new \InvalidArgumentException('Multiple models defined but not inside array');
            }

            if (!empty($models)) {
                $schema = count($models) > 1 ? ['anyOf' => $models] : $models[0];
            } elseif (!empty($properties)) {
                $schema = [];
                $propDesc = [
                    'type' => 'object',
                    'properties' => $properties
                ];
                if ($isArray) {
                    $schema = [
                        'type' => 'array',
                        'items' => $propDesc
                    ];
                } else {
                    $schema = $propDesc;
                }
            } else {
                $schema = new \stdClass;
            }

            // at this time it only works for json responses, we can add other type later
            if (trim((string) $return->getType()->getFqsen(), '\\') === JsonResponse::class) {
                $thisOutput['content'] = [
                    'application/json' => ['schema' => $schema]
                ];
            }

            $responses[$code] = $thisOutput;
        }

        /** @var DocBlock\Tags\Throws $throw */
        foreach ($docblock->getTagsByName('throws') as $throw) {
            $desc = trim((string) $throw->getDescription());
            $code = $this->getResponseCode($desc, 500);
            $responses[$code]['description'] = $desc;
        }

        return $responses;
    }

    /**
     * @param array $route
     * @return array
     */
    private function getSecurity(array $route): array
    {
        $security = [];
        foreach ($route['action']['middleware'] as $middleware) {
            self::$addedSecurity[$route['uri']] = $middleware;
            $secScope = isset(
                self::METHOD_SECURITY_SCOPE_MAP[$route['method']]
            ) ? [self::METHOD_SECURITY_SCOPE_MAP[$route['method']]] : [];
            $security[] = [
                $middleware => $secScope
            ];
        }

        return $security;
    }

    /**
     * @param array $config
     * @return array
     */
    private function getComponents(array $config): array
    {
        $components = [];
        if (!empty(self::$addedModels)) {
            //TODO: add models
            $this->error('Need a way to describe models...');
        }

        if (!empty(self::$addedSecurity)) {
            foreach (self::$addedSecurity as $path => $security) {
                if (!isset($config['security'][$security])) {
                    throw new \InvalidArgumentException(
                        "Invalid security configuration, add a config entry for {$security} @ $path"
                    );
                }
                $components['securitySchemes'][$security] = $config['security'][$security];
            }
        }
        return $components;
    }

    /**
     * @return array
     */
    private static function init(array $config): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $config['title'],
                'contact' => $config['contact'],
                'version' => $config['api_version']
            ],
            'servers' => $config['servers'],
            'paths' => [],
            'tags' => $config['tags']
        ];
    }

    /**
     * @param array $data
     */
    private function writeFile(array $data, array $config): void
    {
        $output = $config['output'];

        file_put_contents(
            base_path($output),
            json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param string $type
     * @return bool
     */
    private function isPrimitiveType(string $type): bool
    {
        return in_array($type, [
            'int', 'float', 'string', 'boolean', 'array'
        ]);
    }

    /**
     * @param array $array
     * @param string $keyToUpdate
     * @param mixed $newValue
     * @return array
     */
    private function updateDeepValue(array $array, string $keyToUpdate, $newValue): array
    {
        $method = __METHOD__;
        array_walk($array, function (&$value, string $key) use ($keyToUpdate, $newValue, $method) {
            if ($key === $keyToUpdate) {
                $value = $newValue;
            } elseif (is_array($value)) {
                $value = call_user_func_array([static::class, $method], [$value, $keyToUpdate, $newValue]);
            }
        });

        return $array;
    }
}
