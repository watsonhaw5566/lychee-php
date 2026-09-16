<?php

declare(strict_types=1);

namespace Lychee\routing;

use Attribute;

/**
 * 资源路由注解，标注在控制器类上。
 *
 * 标注 #[Resource] 的控制器会自动注册以下资源动作路由
 * （方法存在且为 public 时生效，无需再为每个方法声明 #[Route]）：
 *
 *   index()         => GET    /path
 *   save()          => POST   /path
 *   read($id)       => GET    /path/{id}
 *   update($id)     => PUT    /path/{id}  (同时支持 PATCH)
 *   delete($id)     => DELETE /path/{id}
 *   batch_delete()  => DELETE /path
 *
 * 若某个方法已显式声明 #[Route]，则以显式声明为准，自动注册会跳过该方法。
 *
 * 示例：
 *   #[Resource('/users')]
 *   class UserController
 *   {
 *       public function index() {}          // GET    /users
 *       public function save() {}           // POST   /users
 *       public function read($id) {}        // GET    /users/{id}
 *       public function update($id) {}      // PUT    /users/{id}
 *       public function delete($id) {}      // DELETE /users/{id}
 *       public function batch_delete() {}   // DELETE /users
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Resource
{
    public function __construct(
        public string $path,
    ) {
    }
}
