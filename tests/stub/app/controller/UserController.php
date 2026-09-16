<?php

declare(strict_types=1);

namespace Tests\stub\app\controller;

use Lychee\http\JsonResponse;
use Lychee\http\Request;
use Lychee\routing\Resource;
use Lychee\routing\Route;
use Lychee\validation\ValidationException;
use Tests\stub\app\model\User;
use think\Validate;

/**
 * 用户控制器。
 */
#[Resource('users')]
class UserController
{
    public function __construct(
        private readonly User $user,
    ) {
    }

    #[Route('/')]
    public function index(): JsonResponse
    {
        $list = $this->user->select();

        return new JsonResponse([
            'data' => $list,
        ]);
    }

    #[Route('/{id}')]
    public function show(int $id): JsonResponse
    {
        $user = $this->user->find($id);

        if ($user === null) {
            return new JsonResponse(['message' => 'User not found.'], 404);
        }

        return new JsonResponse(['data' => $user]);
    }

    #[Route('/', 'POST')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->all();

        $validate = new Validate();
        $validate->rule([
            'name'  => 'require|max:25',
            'email' => 'require|email',
            'age'   => 'number',
        ])->message([
            'name.require'  => '用户名必填',
            'name.max'      => '用户名不超过25个字符',
            'email.require' => '邮箱必填',
            'email.email'   => '邮箱格式不正确',
        ]);

        if (!$validate->check($data)) {
            /** @var array<string,string> $errors */
            $errors = (array) $validate->getError(true);

            throw new ValidationException($errors);
        }

        $user = $this->user->create([
            'name'  => $data['name'],
            'email' => $data['email'],
            'age'   => (int) ($data['age'] ?? 0),
        ]);

        return new JsonResponse(['data' => $user], 201);
    }
}
