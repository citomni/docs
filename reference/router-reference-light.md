## Router dispatch model (HTTP)

Routes are keyed by path. One effective path match = one route definition =
one controller + action.

The HTTP method is not part of route identity. `route['methods']` is an
access-control allow-list applied to the already matched route, not a route
selector.

Dispatch: match path → check method → instantiate controller → call action.
Method mismatch on a matched route → 405. No path match → 404.

Consequence:
- If GET and POST need different actions, they need different paths.
- One path that must handle both methods uses one action with internal branching.

Method defaults:
- GET implies HEAD automatically.
- OPTIONS is added to every route automatically.
- Omitted `methods` key defaults to GET, HEAD, OPTIONS.

Regex routes:
- Exact path routes are matched before regex routes.
- Regex routes are defined under `routes['regex']`.
- Regex routes are tested in declaration order.
- Parameters use `{name}` syntax.
- Captured values are passed positionally to the action in placeholder order.
- Built-in patterns:
  - `{id}` → `[0-9]+`
  - `{slug}` → `[a-zA-Z0-9-_]+`
  - `{code}` → `[a-zA-Z0-9]+`
  - `{email}` → `[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}`
- Unknown placeholder names use `[^/]+`.

Route definition shape:
```php
'/path.html' => [
    'controller'     => \Vendor\Package\Controller\ExampleController::class,
    'action'         => 'methodName',       // default: 'index'
    'methods'        => ['GET'],            // default: GET, HEAD, OPTIONS
    'template_file'  => 'public/page.html', // optional (see below)
    'template_layer' => 'vendor/package',   // optional (see below)
],
````

Template notes:

* `template_file` and `template_layer` are optional. They are used when the route uses the template engine to render a response.
* When `template_file` and `template_layer` are defined, their values are available in the controller `routeConfig`.

POST-only route that always redirects (no template needed):

```php
'/logout' => [
    'controller' => AuthenticationController::class,
    'action'     => 'logout',
    'methods'    => ['POST'],
],
```
