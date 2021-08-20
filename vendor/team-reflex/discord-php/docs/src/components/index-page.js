import React, { Component } from "react";

import layoutStyles from "./layout.module.css";
import "./layout.css";

import Api from "./api";

export default class IndexPage extends Component {
  constructor(props) {
    super(props);
    this.state = {
      menuActive: false,
    };
    this.onToggleMenu = this.onToggleMenu.bind(this);
    this.isMenuActive = this.isMenuActive.bind(this);
  }

  onToggleMenu() {
    this.setState({
      menuActive: !this.state.menuActive,
    });
  }

  isMenuActive() {
    return this.state.menuActive;
  }

  render() {
    return (
      <div>
        <div className={layoutStyles.container}>
          <Api
            data={this.props.data}
            isMenuActive={this.isMenuActive}
          ></Api>
        </div>
      </div>
    );
  }
}
