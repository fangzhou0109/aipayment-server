<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：商户门户认证控制器（/mapi/auth/*）
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\app\controller\merchant;

use plugin\paymentchannel\app\logic\MerchantAuthLogic;
use plugin\saiadmin\basic\OpenController;
use plugin\saiadmin\utils\Captcha;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户门户认证控制器（/mapi/auth）
 *
 * 继承 OpenController（统一响应 + 无后台登录态），由 MerchantAuth 中间件保护（login 免登录）。
 * 提供：登录（签发独立商户 JWT）、登出、获取当前商户资料。
 */
class AuthController extends OpenController
{
    /**
     * 无需登录的方法（登录入口）
     * @var array
     */
    protected array $noNeedLogin = ['login', 'captcha'];

    /**
     * 认证逻辑层
     * @var MerchantAuthLogic
     */
    protected MerchantAuthLogic $logic;

    /**
     * 构造：注入认证逻辑层
     */
    public function __construct()
    {
        $this->logic = new MerchantAuthLogic();
        parent::__construct();
    }

    /**
     * 登录图形验证码
     * 路由：GET /mapi/auth/captcha
     */
    public function captcha(): Response
    {
        $result = Captcha::imageCaptcha();
        if (($result['result'] ?? 0) !== 1) {
            return $this->fail((string) ($result['message'] ?? '验证码获取失败'));
        }

        return $this->success($result);
    }

    /**
     * 商户登录
     * 路由：POST /mapi/auth/login
     *
     * @param Request $request { login_name, password, code, uuid }
     * @return Response 成功返回 { token, merchant }
     */
    public function login(Request $request): Response
    {
        $uuid = (string) $request->post('uuid', '');
        $code = (string) $request->post('code', '');
        if ($uuid === '' || $code === '' || !Captcha::checkCaptcha($uuid, $code)) {
            return $this->fail('验证码错误');
        }

        $loginName = (string) $request->post('login_name', '');
        $password = (string) $request->post('password', '');
        try {
            $data = $this->logic->login($loginName, $password, $request->getRealIp());
        } catch (Throwable $e) {
            // 登录失败统一转 {code:400}，不泄露内部细节
            return $this->fail($e->getMessage());
        }
        return $this->success($data, '登录成功');
    }

    /**
     * 商户登出
     * 路由：POST /mapi/auth/logout
     *
     * JWT 无状态，前端丢弃 token 即登出；此处仅作语义化应答。
     *
     * @return Response
     */
    public function logout(): Response
    {
        return $this->success([], '已登出');
    }

    /**
     * 获取当前登录商户资料
     * 路由：GET /mapi/auth/info
     *
     * 商户身份由 MerchantAuth 中间件解析并挂在请求头 `merchant`。
     *
     * @param Request $request
     * @return Response
     */
    public function info(Request $request): Response
    {
        // 中间件已校验并透传商户上下文（含 id）
        $context = $request->header('merchant');
        $merchantId = is_array($context) ? (int) ($context['id'] ?? 0) : 0;
        $data = $this->logic->profile($merchantId);
        if ($data === null) {
            return $this->fail('商户不存在');
        }
        return $this->success($data);
    }

    /**
     * 修改门户登录密码
     * 路由：POST /mapi/auth/modifyPassword
     *
     * @param Request $request { old_password, new_password }
     * @return Response
     */
    public function modifyPassword(Request $request): Response
    {
        $context = $request->header('merchant');
        $merchantId = is_array($context) ? (int) ($context['id'] ?? 0) : 0;
        if ($merchantId <= 0) {
            return $this->fail('未登录');
        }

        try {
            $this->logic->modifyPassword(
                $merchantId,
                (string) $request->post('old_password', ''),
                (string) $request->post('new_password', '')
            );
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success([], '密码修改成功');
    }

    /**
     * 上传并设置商户头像
     * 路由：POST /mapi/auth/uploadAvatar（multipart file）
     */
    public function uploadAvatar(Request $request): Response
    {
        $merchantId = $this->resolveMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未登录');
        }

        try {
            $data = $this->logic->uploadAndSetAvatar($merchantId);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success($data, '头像上传成功');
    }

    /**
     * 更新商户头像 URL（传空清除）
     * 路由：POST /mapi/auth/updateAvatar { avatar }
     */
    public function updateAvatar(Request $request): Response
    {
        $merchantId = $this->resolveMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未登录');
        }

        try {
            $data = $this->logic->updateAvatar(
                $merchantId,
                (string) $request->post('avatar', '')
            );
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success($data, '头像已更新');
    }

    /**
     * 从中间件透传的 merchant 头解析商户 ID
     */
    protected function resolveMerchantId(Request $request): int
    {
        $context = $request->header('merchant');

        return is_array($context) ? (int) ($context['id'] ?? 0) : 0;
    }
}
