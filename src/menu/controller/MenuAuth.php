<?php
// +----------------------------------------------------------------------
// | TwoThink [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016 http://www.twothink.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: 艺品网络  593657688@qq.com <www.twothink.cn>
// +----------------------------------------------------------------------
namespace think\menu\controller;

use think\Cache;
use think\Db;
use think\auth\model\AuthRule;
use think\auth\model\AuthGroup;
use app\common\model\Menu as MenuModel;
/**
 * @title 菜单Tree权限验证控制器
 * @author 小矮人 <82550565@qq.com>
 */
class MenuAuth
{
    protected $_extra_menu = [];//动态扩展菜单
    protected $module;//模块
    protected $controller;//控制器
    protected $action;//控制器方法

    /*
    * @title 获取主菜单与当前子菜单tree
    * @author 小矮人 <82550565@qq.com>
    */
    public function getMenuTree(){
        $this->getMenu();
        $this->getSubmenu();
        $menu['main'] = $this->menu;
        $menu['operater'] = array_merge((array)$this->submenu,(array)$this->_extra_menu);
        return $menu;
    }
    /*
     * @title 获取主菜单
     * @author 小矮人 <82550565@qq.com>
     */
    public function getMenu(){
        $model_name = $this->module;
        $controller      = $this->controller;
        $action_name = $this->action;

        $pid = Db::name('Menu')->where("pid !=0 AND url like '%{$controller}/".$action_name."%'")->value('pid');
        $pid = $this->getMenuPid($pid);

        if(config('develop_mode') == 1)
            Cache::set('getmenu_menu_list_'.$pid,null);
        $menus  =   Cache::get('getmenu_menu_list_'.$pid);
        if(!$menus){
            // 获取主菜单
            $where['pid']   =   0;
            $where['hide']  =   0;
            $where['module']  =   $model_name;
            if(!config('develop_mode')){ // 是否开发者模式
                $where['is_dev']    =   0;
            }
            $menus =   Db::name('Menu')->where($where)->order('sort asc')->field('id,title,url,icon')->select();
            foreach ($menus as $key => $item) {
                // 判断主菜单权限
                if ( !IS_ROOT && !$this->checkRule(strtolower($model_name.'/'.$item['url']),AuthRule::rule_main,null) ) {
                    unset($menus[$key]);
                    continue;//继续循环
                }
                $menus[$key]['url'] = url($item['url']);
            }
            $menus = Array_mapping($menus,'id');
            $menus[$pid]['current'] = true;//当前菜单高亮
            Cache::set('getmenu_menu_list_'.$pid,$menus);
        }
        $this->menu = $menus;
        return $this;
    }
    /*
     * @title 获取最顶级菜单
     * @param $pid 上级id
     * @author 小矮人 <82550565@qq.com>
     */
    protected function getMenuPid($pid){
        $nav =  Db::name('Menu')->find($pid);
        if($nav['pid']){
            return $nav    =   $this->getMenuPid($nav['pid']);
        }
        return $nav['id'];
    }
    /*
     * @title 获取子菜单
     * @param $pid 上级id
     * @author 小矮人 <82550565@qq.com>
     */
    public function getSubmenu($pid=false){
        if(!$pid){
            $pid = Db::name('Menu')->where("pid !=0 AND url like '%{$this->controller}/".$this->action."%'")->value('pid');
            $pid = $this->getMenuPid($pid);
        }
        $model_name = $this->module;
        $submenu  =   Cache::get('getmenu_submenu_list_'.$model_name);
        if(!$submenu || config('develop_mode') == 1) {
            //获取所有分类的合法url
            $where = [];
            $where['hide'] = 0;
            $where['module']  =   $model_name;
            if (!config('develop_mode')) { // 是否开发者模式
                $where['is_dev'] = 0;
            }
            $second_urls = Db::name('Menu')->where($where)->column('id,url');
            $second_urls = array_filter($second_urls);
            if (!IS_ROOT) {
                // 检测菜单权限
                $to_check_urls = [];
                foreach ($second_urls as $key => $to_check_url) {
                    if (stripos($to_check_url, $model_name) !== 0) {
                        $rule = $model_name . '/' . $to_check_url;
                    } else {
                        $rule = $to_check_url;
                    }
                    if ($this->checkRule($rule, AuthRule::rule_url, null))
                        $to_check_urls[] = $to_check_url;
                }
            }

            if (isset($to_check_urls)) {
                if (empty($to_check_urls)) {
                    // 没有任何权限
                    return $this;
                } else {
                    $where['url'] = array('in', $to_check_urls);
                }
            }
            $where['is_menu'] = ['in', 1];
            $MenuModel = new MenuModel();
            $submenu = $MenuModel->where($where)->field('id,pid,title,url,tip,icon,module,sort')->order('sort asc')->select();
            if (is_object($submenu))
                $submenu = $submenu->toArray();

            Cache::set('getmenu_submenu_list_'.$model_name,$submenu);
        }
        $submenu = list_to_tree($submenu, 'id', 'pid', 'operater',$pid);
        $this->submenu = $submenu;
        return $this;
    }
    /**
     * @title 内容菜单，进行权限控制
     * @author 小矮人 <82550565@qq.com>
     */
    public function getCategory(){
        //获取动态分类
        $cate_auth  =   AuthGroup::getAuthCategories(UID); //获取当前用户所有的内容权限节点
        $cate_auth  =   $cate_auth == null ? array() : $cate_auth;
        $cate       =   Db::name('Category')->where(['status'=>1])->field('id,title,pid,allow_publish')->order('pid,sort')->select();
        //没有权限的分类则不显示
        if(!IS_ROOT){
            foreach ($cate as $key=>$value){
                if(!in_array($value['id'], $cate_auth)){
                    unset($cate[$key]);
                }
            }
        }
        //生成每个分类的url
        foreach ($cate as $key=>&$value){
            $value['url'] = url('Article/index?cate_id='.$value['id']);
        }
        //获取分类id
        $cate_id        =   input('param.cate_id');
        //是否展开分类
        $hide_cate = false;
        $action_name = $this->action;
        if($action_name != 'recycle' && $action_name != 'draftbox' && $action_name != 'mydocument'){
            $hide_cate  =   true;
        }
        //添加展开分类标识
        if($cate_id && $hide_cate){
            $cate = Array_mapping($cate,'id');
            $cate = $this->getCategoryisshow($cate,$cate_id);
        }
        //生成分类树
        $cate           =   list_to_tree($cate,'id','pid','operater');
        return $cate;
    }
    //内容分类展开标识
    protected function getCategoryisshow($arr,$pid){
        if($arr[$pid]['pid'] > 0){
            $arr[$pid]['current'] = true;
            return $this->getCategoryisshow($arr,$arr[$pid]['pid']);
        }else{
            $arr[$pid]['current'] = true;
            return $arr;
        }
    }
    /*
     * @title 动态扩展菜单
     */
    public function extra_menu($array){
        $this->_extra_menu = $array;
        return $this;
    }
    /**
     * 权限检测
     * @param string  $rule    检测的规则
     * @param string  $mode    验证模式
     * @return boolean
     */
    final protected function checkRule($rule, $type=AuthRule::rule_url, $mode='url'){
        static $Auth    =   null;
        if (!$Auth) {
            $Auth       =   new \com\Auth();
        }
        if(!$Auth->check($rule,UID,$type,$mode)){
            return false;
        }
        return true;
    }
    /**
     * 修改器 设置数据对象值
     * @access public
     * @param string(array) $name  属性名
     * @param mixed  $value 属性值
     * @return $this
     */
    public function setAttr($name,$value=''){
        if(is_array($name)){
            foreach ($name as $key=>$value){
                $this->$key = $value;
            }
        }else{
            $this->$name = $value;
        }
        return $this;
    }

    /*
     * @title 设置属性
     */
    public function __set($name, $value)
    {
        $this->$name = $value;
    }
}