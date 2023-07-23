import Component from './adapter';
import config from '../../../config.json';

export default class ReforwardDisplay {
  static pluginName = config.name_en; // 插件名称
  static version = config.version; // 插件版本
  static author = config.author;
  static type = config.type; // 插件类型
  static appid = config.app_id; // appid
  static platform = config.view.ReforwardDisplay.platform;
  static target = config.view.ReforwardDisplay.target; // 插件使用目标
  static hookName = config.view.ReforwardDisplay.hookName; // 钩子名称
  static component = (<Component />); // 需要渲染的组件
  static options = {}; // 需要在注入时提供的额外数据
}