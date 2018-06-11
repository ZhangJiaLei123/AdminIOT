<?php
// +----------------------------------------------------------------------
// | AdminIOT
// +----------------------------------------------------------------------
// | Copyright (c) 2017 https://www.adminiot.com.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed (Without the authorship of the author, the code can not be
// | transmitted two times or used for other business practices)
// +----------------------------------------------------------------------
// | Author: Robert <78320701@qq.com> Date:2017/12
// +----------------------------------------------------------------------

namespace app\admin\controller;

use think\Controller;
use app\admin\auth\Auth;
use app\admin\auth\Tree;
use think\exception\ClassNotFoundException;
use think\exception\HttpResponseException;
use think\Log;
use think\Request;
use think\Response;
use think\Session;
use think\Db;
use app\common\model\AdminUsers;
use think\Url;
use think\Config;

class Base extends Controller
{

    protected $request, $request_type, $param, $post, $get, $module, $controller, $action, $url, $do_url, $id, $web_data, $api_result, $model;

    public function __construct()
    {
        $this->request = Request::instance();
        $this->request_type = $this->request->isGet() ? 1 : ($this->request->isPost() ? 2 : ($this->request->isPut() ? 3 : ($this->request->isDelete() ? 4 : 0)));
        
        $this->param = $this->request->param();
        $this->post = $this->request->post();
        $this->get = $this->request->get();
        $this->module = $this->request->module();
        $this->controller = $this->request->controller();
        $this->action = $this->request->action();
        $this->do_url = $this->parseName($this->module) . "/" . $this->parseName($this->controller) . "/";
        $this->url = $this->parseName($this->module) . '/' . $this->parseName($this->controller) . "/" . $this->parseName($this->action);
        $this->id = isset($this->param['id']) ? $this->param['id'] : - 1;
        $this->api_result = [
            'status' => 404,
            'extend' => [],
            'result' => [],
            'message' => '',
            'timestamp' => time()
        ];
        
        parent::__construct();
    }

    public function _initialize()
    {
        $auth = new Auth();
        if ($this->request->isAjax()) {
            if ($auth->is_login()) {
                $user_id = Session::get('user.user_id');
                if ($user_id != 1) {
                    if (! $auth->check($this->url, $user_id)) {
                        // 未授权
                        $this->api_result['status'] = 403;
                        $this->api_result['message'] = 'Unauthorized';
                        throw new HttpResponseException(json($this->api_result));
                    }
                }
                
                if (isset($this->post['id'])) {
                    $this->id = $this->post['id'];
                }
            } else {
                // 未登录
                $this->api_result['status'] = 401;
                $this->api_result['message'] = ' Not logged in';
                throw new HttpResponseException(json($this->api_result));
            }
        } else {
            if ($auth->is_login()) {
                $user_id = Session::get('user.user_id');
                if ($user_id != 1) {
                    if (! $auth->check($this->url, $user_id)) {
                        $this->do_error('当前用户无操作权限');
                    }
                }
                
                
                $this->web_data['do_url'] = $this->do_url;
                
                // 判断当前页面是否是添加或者编辑页面，然后启用jquery.valitade.js插件
                if (($this->action == 'add' || $this->action == 'edit') && ($this->request->isGet() == true)) {
                    $this->web_data['valitade_js'] = 1;
                }
                
                // 增删改
                if (! ($this->action == 'add' || $this->action == 'edit') && ($this->request->isGet() == true)) {
                    
                    $this->web_data['data_add_url'] = url($this->do_url . 'add');
                    $this->web_data['data_add_title'] = Db::name('admin_menus')->where("url='" . $this->do_url . "add'")->value('title');
                    $this->web_data['data_del_url'] = url($this->do_url . 'del');
                    $this->web_data['data_edit_url'] = url($this->do_url . 'edit');
                }
                
                if (isset($_COOKIE['page_list_rows'])) {
                    $rows = $_COOKIE['page_list_rows'];
                    if (0 < $rows && 100 >= $rows) {
                        $this->web_data['list_rows'] = $rows;
                    }else 
                        $this->web_data['list_rows'] =Config::get('paginate')['list_rows'];
                }else
                    $this->web_data['list_rows'] = Config::get('paginate')['list_rows'];
                
               
                $this->web_data['left_menu'] = $this->getLeftMenu();
                $menu_info = $this->getMenuInfo();
                $this->web_data['web_title'] = $menu_info['title'];
                $this->web_data['log_type'] = $menu_info['log_type'];
                $user_info = $this->getUserInfo($user_id);
                $this->web_data['user_info'] = $user_info;
                
                $log_type = $this->web_data['log_type'];
                if ($log_type == $this->request_type && $log_type != 0) {
                    $auth->createLog($this->web_data['web_title'], $log_type, $this->id);
                }
            } else {
                $this->redirect('/', [
                    'uri' => $this->url
                ]);
            }
        }
    }

    protected function getMenuChild($arr, $myid)
    {
        $a = $newarr = array();
        if (is_array($arr)) {
            foreach ($arr as $id => $a) {
                if ($a['parent_id'] == $myid)
                    $newarr[$id] = $a;
            }
        }
        return $newarr ? $newarr : false;
    }

    protected function getMenuParent($arr, $myid, $parent_ids = array())
    {
        $a = $newarr = array();
        if (is_array($arr)) {
            foreach ($arr as $id => $a) {
                if ($a['menu_id'] == $myid) {
                    if ($a['parent_id'] != 0) {
                        array_push($parent_ids, $a['parent_id']);
                        $parent_ids = $this->getMenuParent($arr, $a['parent_id'], $parent_ids);
                    }
                }
            }
        }
        return ! empty($parent_ids) ? $parent_ids : false;
    }
    
    // 获当前url取面包屑
    protected function getBreadcrumb()
    {
        $menus = Db::name('admin_menus')->where('is_show=1')->select();
    }

    protected function getCurrentNav($arr, $myid, $parent_ids = array(), $current_nav = '', $firstcall = 1)
    {
        $a = $newarr = array();
        if (is_array($arr)) {
            foreach ($arr as $id => $a) {
                if ($a['menu_id'] == $myid) {
                    
                    if (empty($a['url']) || $firstcall)
                        $url = "javascript:void(0);";
                    else
                        $url = url($a['url']);
                    
                    if ($a['parent_id'] != 0) {
                        array_push($parent_ids, $a['parent_id']);
                        $ru = '<li><a href=' . $url . ' ><i class="fa ' . $a['icon'] . '"></i> ' . $a['title'] . '</a></li>';
                        $current_nav = $ru . $current_nav;
                        $temp_result = $this->getCurrentNav($arr, $a['parent_id'], $parent_ids, $current_nav, 0);
                        $parent_ids = $temp_result[0];
                        $current_nav = $temp_result[1];
                    } else {
                        $ru = '<li><a href=' . $url . '><i class="fa ' . $a['icon'] . '"></i> ' . $a['title'] . '</a></li>';
                        $current_nav = $ru . $current_nav;
                    }
                }
            }
        }
        return ! empty([
            $parent_ids,
            $current_nav
        ]) ? [
            $parent_ids,
            $current_nav
        ] : false;
    }

    protected function getMenuInfo()
    {
        return Db::name('admin_menus')->where([
            'url' => $this->url
        ])->find();
    }

    protected function getUserInfo($user_id)
    {
        $user_info = AdminUsers::get($user_id);
        return $user_info;
    }

    public function _empty()
    {
        return $this->do_error('页面不存在');
    }

    /**
     * 添加，修改时返回成功信息方法
     * 
     * @param string $msg            
     * @param string $url            
     * @param string $data            
     */
    protected function do_success($msg = '操作成功！', $url = '', $params = [])
    {
        if ($url == '') {
            $url = $this->do_url . 'index';
        }
        
        return $this->redirect($url, $params, 302, [
            'success_message' => $msg
        ]);
    }

    /**
     * 添加，修改时返回错误信息方法
     * 
     * @param string $msg            
     * @param null $url            
     * @param string $data            
     */
    protected function do_error($msg = '操作失败', $url = null, $data = '')
    {
        $server = $this->request->server();
        if ($url == null && isset($server['HTTP_REFERER'])) {
            $url = $server['HTTP_REFERER'];
        }
        $current_url = $server['REQUEST_SCHEME'] . $server['SERVER_NAME'] . $server['REQUEST_URI'];
        if ($url == $current_url || $this->url == '' || $url == null) {
            $msg = '页面不存在！';
            $url = 'admin/stat/index';
        }
        Log::write($this->param, 'error');
        return $this->redirect($url, $data, 302, [
            'error_message' => $msg,
            'form_info' => $this->param
        ]);
    }

 
    /**
     * 获取左侧菜单
     * 
     * @return mixed
     */
    protected function getLeftMenu()
    {
        $auth = new Auth();
        $user_id = Session::get('user.user_id');
        $menu = $auth->getMenuList($user_id, 1);
        
        $menus_all = $menu[0];
        $menus_show = $menu[1];
        
        $max_level = 0;
        $current_id = 1;
        $parent_ids = array(
            0 => 0
        );
        
        // var_dump($menus_all);
        // var_dump($this->url);
        
        $current_nav = [
            '',
            ''
        ];
        foreach ($menus_all as $k => $v) {
            if ($v['url'] == $this->url) {
                // var_dump($this->url);
                $parent_ids = $this->getMenuParent($menus_all, $v['menu_id']);
                $current_id = $v['menu_id'];
                $current_nav = $this->getCurrentNav($menus_all, $v['menu_id']);
            }
        }
        if ($parent_ids == false) {
            $parent_ids = array(
                0 => 0
            );
        }
        
        // var_dump($parent_ids,"parent_ids");
        // var_dump($current_nav,"current_nav");
        $this->web_data['current_nav'] = $current_nav[1];
        
        $tree = new Tree();
        
        foreach ($menus_show as $k => $v) {
            $url = url($v['url']);
            $menus_show[$k]['icon'] = ! empty($v['icon']) ? $v['icon'] : 'fa fa-list';
            $menus_show[$k]['level'] = $tree->get_level($v['menu_id'], $menus_show);
            $max_level = $max_level <= $menus_show[$k]['level'] ? $menus_show[$k]['level'] : $max_level;
            $menus_show[$k]['url'] = $url;
        }
        
        // 如果$current_id 处于隐藏状态，则菜单树里面需要找到上一级不属于隐藏状态菜单，激活它。
        $current_active_id = $current_id;
        if ($menus_all[$current_active_id]['is_show'] != 1) {
            if (! empty($parent_ids)) {
                foreach ($parent_ids as $id) {
                    if ($menus_all[$id]['is_show'] == 1) {
                        $current_active_id = $id;
                        break;
                    }
                }
            }
        }
        
        // var_dump($current_active_id,"current_active_id");
        $tree->init($menus_show);
        
        $text_base_one = "<li class='treeview";
        $text_hover = " active";
        $text_base_two = "'><a href='javascript:void(0);'><i class='fa \$icon'></i><span>\$title</span>
                             <span class='pull-right-container'><i class='fa fa-angle-left pull-right'></i></span>
                             </a><ul class='treeview-menu";
        $text_open = " menu-open";
        $text_base_three = "'>";
        
        $text_base_four = "<li";
        $text_hover_li = " class='active'";
        $text_base_five = ">
                            <a href='\$url'>
                            <i class='fa \$icon'></i>
                            <span>\$title</span>
                            </a>
                         </li>";
        
        $text_0 = $text_base_one . $text_base_two . $text_base_three;
        $text_1 = $text_base_one . $text_hover . $text_base_two . $text_open . $text_base_three;
        $text_2 = "</ul></li>";
        $text_current = $text_base_four . $text_hover_li . $text_base_five;
        $text_other = $text_base_four . $text_base_five;
        
        for ($i = 0; $i <= $max_level; $i ++) {
            $tree->text[$i]['0'] = $text_0;
            $tree->text[$i]['1'] = $text_1;
            $tree->text[$i]['2'] = $text_2;
        }
        
        $tree->text['current'] = $text_current;
        $tree->text['other'] = $text_other;
        
        return $tree->get_authTree(0, $current_active_id, $parent_ids);
    }

    /**
     * 字符串命名风格转换
     * type 0 将Java风格转换为C的风格 1 将C风格转换为Java的风格
     * 
     * @param string $name
     *            字符串
     * @param integer $type
     *            转换类型
     * @param bool $ucfirst
     *            首字母是否大写（驼峰规则）
     * @return string
     */
    protected function parseName($name, $type = 0, $ucfirst = true)
    {
        if ($type) {
            $name = preg_replace_callback('/_([a-zA-Z])/', function ($match) {
                return strtoupper($match[1]);
            }, $name);
            return $ucfirst ? ucfirst($name) : lcfirst($name);
        } else {
            return strtolower(trim(preg_replace("/[A-Z]/", "_\\0", $name), "_"));
        }
    }

    protected function fetch($template = '', $vars = [], $replace = [], $config = [])
    {
        parent::assign([
            'web_data' => $this->web_data
        ]);
        return parent::fetch($template, $vars, $replace, $config);
    }

 
    public function paginate($listRows = null, $total, $simple = false, $config = [])
    {
        if (is_int($simple)) {
            $total = $simple;
            $simple = false;
        }
        if (is_array($listRows)) {
            $config = array_merge(Config::get('paginate'), $listRows);
            $listRows = $config['list_rows'];
        } else {
            $config = array_merge(Config::get('paginate'), $config);
            $listRows = $listRows ?  : $config['list_rows'];
        }
        
        /**
         * @var Paginator $class
         */
        $class = false !== strpos($config['type'], '\\') ? $config['type'] : '\\think\\paginator\\driver\\' . ucwords($config['type']);
        
        // 获取当前分页页面
        $page = isset($config['page']) ? (int) $config['page'] : call_user_func([
            $class,
            'getCurrentPage'
        ], $config['var_page']);
        
        $page = $page < 1 ? 1 : $page;
        
        $config['path'] = isset($config['path']) ? $config['path'] : call_user_func([
            $class,
            'getCurrentPath'
        ]);
        
        return $class::make([], $listRows, $page, $total, $simple, $config);
    }
}