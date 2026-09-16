# 命令行 Console

通过 `lee` 入口脚本执行命令。

## 入口

项目根目录下的 `lee` 文件：

```bash
php lee list                    # 列出所有命令
php lee run                     # 启动内置开发服务器
php lee run --port=8080         # 指定端口
php lee run --host=0.0.0.0      # 指定监听地址
```

## 内置命令

| 命令 | 说明 |
|------|------|
| `list` | 列出所有可用命令 |
| `run` | 启动 PHP 内置开发服务器（自动处理 public/ 静态文件） |
| `route:list` | 列出所有已注册的路由 |
| `migrate:run` | 执行数据库迁移 |
| `migrate:rollback` | 回滚迁移 |
| `seed:run` | 执行数据填充 |
| `cron:run` | 执行定时任务 |
| `queue:work` | 队列消费 |

### run 开发服务器

`run` 命令启动 PHP 内置服务器，`public/` 目录作为文档根：

- 静态文件（CSS、JS、图片等）直接从 `public/` 提供，不经过 PHP
- 其余请求交由应用路由处理

```bash
php lee run                          # http://127.0.0.1:8000
php lee run --port=9000              # http://127.0.0.1:9000
php lee run --host=0.0.0.0 --port=80 # 监听所有网卡
```

### route:list 路由列表

`route:list` 命令列出所有已注册的路由，包括 HTTP 方法、路径和对应的控制器方法：

```bash
php lee route:list
```

输出示例：

```
Registered routes: (8)

  GET     /users       App\controller\UserController@index
  POST    /users       App\controller\UserController@save
  GET     /users/{id}  App\controller\UserController@read
  PUT     /users/{id}  App\controller\UserController@update
  PATCH   /users/{id}  App\controller\UserController@update
  DELETE  /users/{id}  App\controller\UserController@delete
  DELETE  /users       App\controller\UserController@batch_delete
  GET     /            App\controller\IndexController@index
```

## 自定义命令

继承 `Lychee\console\Command`：

```php
// app/command/HelloCommand.php
namespace App\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;

class HelloCommand extends Command
{
    protected string $name = 'hello';
    protected string $description = 'Say hello';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
        $this->addArgument('name', \Lychee\console\input\Argument::OPTIONAL, 'Your name', 'World');
    }

    protected function execute(Input $input, Output $output): int
    {
        $name = $input->getArgument('name');
        $output->writeln("<info>Hello, {$name}!</info>");

        return 0;
    }
}
```

## 输出样式

```php
$output->writeln('<info>成功</info>');
$output->writeln('<comment>提示</comment>');
$output->writeln('<error>错误</error>');
```
