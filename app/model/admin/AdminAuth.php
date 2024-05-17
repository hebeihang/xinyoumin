<?php

namespace app\model\admin;

use app\common\library\helper\TreeHelper;
use app\model\BaseModel;

class AdminAuth extends BaseModel
{
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 获取父ID选项信息
    public static function getPidOptions($order = ['sort', 'id' => 'desc']): array
    {
        $list = self::order($order)
            ->select()
            ->toArray();
        $list = TreeHelper::tree($list);
        $result = [];
        foreach ($list as $k => $v) {
            $result[$v['id']] = $v['left_title'];
        }
        return $result;
    }

    public static function getMenu(): array
    {
        $list = self::order(['sort', 'id' => 'desc'])
            ->where('status',1)
            ->select()
            ->toArray();
        $list = TreeHelper::tree($list);
        $result = [];
        foreach ($list as $k => $v) {
            $result[$v['id']] = $v['left_title'];
        }
        return $result;
    }

    /**
     * 创建菜单
     * @param array $menu
     * @param mixed $parent 父类的name或pid
     */
    public static function createMenu($menu, $parent = 0)
    {
        if (!is_numeric($parent)) {
            $parentRule = self::getByName($parent);
            $pid = $parentRule ? $parentRule['id'] : 0;
        } else {
            $pid = $parent;
        }
        $allow = array_flip(['type', 'name', 'title', 'icon', 'condition','menu']);

        foreach ($menu as $k => $v) {
            $hasChild = isset($v['child']) && $v['child'];
            $data = array_intersect_key($v, $allow);
            $data['menu'] = $data['menu'] ?? ($hasChild ? 1 : 0);
            $data['icon'] = $data['icon'] ?? 'icon-list';
            $data['pid'] = $pid;
            $data['status'] = 1;
            $menu = self::create($data);
            if ($hasChild) {
                self::createMenu($v['child'], $menu->id);
            }
        }
    }

    /**
     * 删除菜单
     * @param string $name 规则name
     * @return boolean
     */
    public static function deleteMenu($name)
    {
        $ids = self::getAuthRuleIdsByName($name);
        if (!$ids) {
            return false;
        }
        AdminAuth::destroy($ids);
        return true;
    }

    /**
     * 启用菜单
     * @param string $name
     * @return boolean
     */
    public static function enableMenu($name)
    {
        $ids = self::getAuthRuleIdsByName($name);
        if (!$ids) {
            return false;
        }
        AdminAuth::whereIn('id', $ids)->update(['status' => 1]);
        return true;
    }

    /**
     * 禁用菜单
     * @param string $name
     * @return boolean
     */
    public static function disableMenu($name)
    {
        $ids = self::getAuthRuleIdsByName($name);
        if (!$ids) {
            return false;
        }
        AdminAuth::whereIn('id', $ids)->update(['status' => 0]);
        return true;
    }

    //导出指定名称的菜单规则
    public static function exportMenu($name)
    {
        $ids = self::getAuthRuleIdsByName($name);
        if (!$ids) {
            return [];
        }
        $menuList = [];
        $menu = AdminAuth::getByName($name);
        if ($menu) {
            $ruleList = AdminAuth::whereIn('id', $ids)->select()->toArray();
            $menuList = TreeHelper::instance()->init($ruleList)->getTreeArray($menu['id']);
        }
        return $menuList;
    }

    public static function getAuthRuleIdsByName($name)
    {
        $ids = [];
        $menu = self::getByName($name);
        if ($menu) {
            // 必须将结果集转换为数组
            $ruleList = self::order('weigh', 'desc')->field('id,pid,name')->select()->toArray();
            // 构造菜单数据
            $ids = TreeHelper::instance()->init($ruleList)->getChildrenIds($menu['id'], true);
        }
        return $ids;
    }

}