/*
 * This file is part of AtroPIM.
 *
 * AtroPIM - Open Source PIM application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 * Website: https://atropim.com
 *
 * AtroPIM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroPIM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AtroPIM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "AtroPIM" word.
 */

Espo.define('pim:views/product/record/detail', 'pim:views/record/detail',
    Dep => Dep.extend({

        template: 'pim:product/record/detail',

        catalogTreeData: null,

        notSavedFields: ['image'],

        isCatalogTreePanel: false,

        showEmptyRequiredFields: true,

        notFilterFields: ['assignedUser', 'ownerUser', 'teams'],

        beforeSaveModel: [],

        sideView: "pim:views/product/record/detail-side",

        setup() {
            Dep.prototype.setup.call(this);

            if (!this.model.isNew() && (this.type === 'detail' || this.type === 'edit') && this.getMetadata().get(['scopes', this.scope, 'advancedFilters'])) {
                this.beforeSaveModel = this.model.getClonedAttributes();
                this.listenTo(this.model, 'main-image-updated', () => {
                    this.applyOverviewFilters();
                });

                this.listenTo(this.model, 'change', () => {
                    this.applyOverviewFilters();
                });

                this.listenTo(this.model, 'after:save', () => {
                    this.beforeSaveModel = this.model.getClonedAttributes();
                    this.applyOverviewFilters();
                });
            }

            if (!this.isWide && this.type !== 'editSmall' && this.type !== 'detailSmall'
                && this.getAcl().check('Catalog', 'read') && this.getAcl().check('Category', 'read')) {
                this.isCatalogTreePanel = true;
                this.setupCatalogTreePanel();
            }

            // refresh attributes panel after any saving
            this.listenTo(this.model, 'after:save', () => {
                $(".panel-productAttributeValues button[data-action='refresh']").click();
            });
        },

        setupCatalogTreePanel() {
            this.createView('catalogTreePanel', 'pim:views/product/record/catalog-tree-panel', {
                el: `${this.options.el} .catalog-tree-panel`,
                scope: this.scope,
                model: this.model
            }, view => {
                view.listenTo(view, 'select-category', data => this.navigateToList(data));
            });
        },

        navigateToList(data) {
            this.catalogTreeData = Espo.Utils.cloneDeep(data || {});
            const options = {
                isReturn: true,
                callback: this.expandCatalogTree.bind(this)
            };
            this.getRouter().navigate(`#${this.scope}`);
            this.getRouter().dispatch(this.scope, null, options);
        },

        expandCatalogTree(list) {
            list.sortCollectionWithCatalogTree(this.catalogTreeData);
            list.render();
        },

        data() {
            let data = Dep.prototype.data.call(this);
            this.beforeSaveModel = this.model.getClonedAttributes();

            return _.extend({
                isCatalogTreePanel: this.isCatalogTreePanel
            }, data)
        },

        applyOverviewFilters() {
            let fields = this.getFilterFieldViews();
            Object.keys(fields).forEach(name => {
                let value = (name in this.beforeSaveModel) ? this.beforeSaveModel[name] : this.model.get(name);
                if (!this.notFilterFields.includes(name)
                    && (!this.isEmptyRequiredField(name, value) || this.hasCompleteness())
                ) {
                    let fieldView = fields[name],
                        // fields filter
                        hide = this.fieldsFilter(name, fieldView);

                    if (this.getConfig().get('isMultilangActive')) {
                        if (!hide) {
                            // multi-language fields filter
                            hide = this.multiLangFieldsFilter(name, fieldView);
                        }
                        if (!hide) {
                            // hide generic fields
                            hide = this.genericFieldsFilter(name, fieldView);
                        }
                    }

                    this.controlFieldVisibility(fieldView, hide);
                }
            });

            // trigger
            this.model.trigger('overview-filters-applied');
        },

        getFilterFieldViews: function () {
            let fields = {};
            $.each(this.getFieldViews(), function (name, fieldView) {
                if (!fieldView.model.getFieldParam(name, 'advancedFilterDisabled')) {
                    fields[name] = fieldView;
                }
            });

            return fields;
        },

        fieldsFilter: function (name, fieldView) {
            // get filter param
            let filter = (this.model.advancedEntityView || {}).fieldsFilter;

            let actualFields = this.getFieldManager().getActualAttributeList(fieldView.model.getFieldType(name), name);
            let actualFieldValues = actualFields.map(field => (field in this.beforeSaveModel) ? this.beforeSaveModel[field] : fieldView.model.get(field));
            actualFieldValues = actualFieldValues.concat(this.getAlternativeValues(fieldView));

            return !actualFieldValues.every(value => this.checkFieldValue(filter, value, fieldView.isRequired()));
        },

        multiLangFieldsFilter: function (name, fieldView) {
            // get locale
            let locale = (this.model.advancedEntityView || {}).localesFilter,
                isMultiLang = fieldView.model.getFieldParam(name, 'isMultilang'),
                multilangLocale = fieldView.model.getFieldParam(name, 'multilangLocale'),
                hide = false;

            if (locale !== null && locale !== '') {
                if ((multilangLocale !== null && multilangLocale !== locale)
                    || (isMultiLang === false && multilangLocale === null)
                    || isMultiLang === null) {
                    hide = true;
                }
            }

            return hide;
        },

        genericFieldsFilter: function (name, fieldView) {
            // prepare filter param
            let filter = (this.model.advancedEntityView || {}).showGenericFields,
                isMultilang = fieldView.model.getFieldParam(name, 'multilangLocale') || false,
                hide = false;

            if (!isMultilang && !filter) {
                hide = true;
            }

            return hide;
        },

        isEmptyRequiredField: function (field, value) {
          return this.showEmptyRequiredFields
              && this.getMetadata().get(['entityDefs', this.scope, 'fields', field, 'required']) === true
              && (value === null || value === '' || (Array.isArray(value) && !value.length));
        },

        hotKeySave: function (e) {
            e.preventDefault();
            if (this.mode === 'edit') {
                this.actionSave();
            } else {
                let viewsFields = this.getFieldViews();
                Object.keys(viewsFields).forEach(item => {
                    if (viewsFields[item].mode === "edit" ) {
                        viewsFields[item].inlineEditSave();
                    }
                });
            }
        },

        afterNotModified(notShow) {
            if (!notShow) {
                let msg = this.translate('notModified', 'messages');
                Espo.Ui.warning(msg, 'warning');
            }
            this.enableButtons();
        },

        getBottomPanels() {
            let bottomView = this.getView('bottom');
            if (bottomView) {
                return bottomView.nestedViews;
            }
            return null;
        },

        setDetailMode() {
            let panels = this.getBottomPanels();
            if (panels) {
                for (let panel in panels) {
                    if (typeof panels[panel].setListMode === 'function') {
                        panels[panel].setListMode();
                    }
                }
            }
            Dep.prototype.setDetailMode.call(this);
        },

        setEditMode() {
            let panels = this.getBottomPanels();
            if (panels) {
                for (let panel in panels) {
                    if (typeof panels[panel].setEditMode === 'function') {
                        panels[panel].setEditMode();
                    }
                }
            }
            Dep.prototype.setEditMode.call(this);
        },

        cancelEdit() {
            let panels = this.getBottomPanels();
            if (panels) {
                for (let panel in panels) {
                    if (typeof panels[panel].cancelEdit === 'function') {
                        panels[panel].cancelEdit();
                    }
                }
            }
            Dep.prototype.cancelEdit.call(this);
        },

        handlePanelsFetch() {
            let changes = false;
            let panels = this.getBottomPanels();
            if (panels) {
                for (let panel in panels) {
                    if (typeof panels[panel].panelFetch === 'function') {
                        changes = panels[panel].panelFetch() || changes;
                    }
                }
            }
            return changes;
        },

        validatePanels() {
            let notValid = false;
            let panels = this.getBottomPanels();
            if (panels) {
                for (let panel in panels) {
                    if (typeof panels[panel].validate === 'function') {
                        notValid = panels[panel].validate() || notValid;
                    }
                }
            }
            return notValid
        },

        handlePanelsSave() {
            let panelsData = {};
            let panels = this.getBottomPanels();
            if (panels) {
                for (let panel in panels) {
                    if (typeof panels[panel].panelFetch === 'function') {
                        panelsData[panel] = panels[panel].panelFetch();
                    }
                }
            }

            return panelsData;
        },

        save(callback, skipExit) {
            (this.notSavedFields || []).forEach(field => {
                const keys = this.getFieldManager().getAttributeList(this.model.getFieldType(field), field);
                keys.forEach(key => delete this.model.attributes[key]);
            });

            this.beforeBeforeSave();

            let data = this.fetch();

            let self = this;
            let model = this.model;

            let initialAttributes = this.attributes;

            let beforeSaveAttributes = this.model.getClonedAttributes();

            data = _.extend(Espo.Utils.cloneDeep(beforeSaveAttributes), data);

            let gridInitPackages = false;
            let packageView = false;
            let bottomView = this.getView('bottom');
            if (bottomView) {
                packageView = bottomView.getView('productTypePackages');
                if (packageView) {
                    gridInitPackages = packageView.getInitAttributes();
                }
            }

            let attrs = false;
            let gridPackages = false;
            if (model.isNew()) {
                attrs = data;
            } else {
                for (let name in data) {
                    if (name !== 'id'&& gridInitPackages && Object.keys(gridInitPackages).indexOf(name) > -1) {
                        if (!_.isEqual(gridInitPackages[name], data[name])) {
                            (gridPackages || (gridPackages = {}))[name] = data[name];
                        }
                        continue;
                    }

                    if (_.isEqual(initialAttributes[name], data[name])) {
                        continue;
                    }
                    (attrs || (attrs = {}))[name] = data[name];
                }
            }

            let beforeSaveGridPackages = false;
            if (gridPackages && packageView) {
                let gridModel = packageView.getView('grid').model;
                beforeSaveGridPackages = gridModel.getClonedAttributes();
                gridModel.set(gridPackages, {silent: true})
            }

            if (attrs) {
                model.set(attrs, {silent: true});
            }

            const panelsChanges = this.handlePanelsFetch();

            const overviewValidation = this.validate();
            const panelValidation = this.validatePanels();

            if (overviewValidation || panelValidation) {
                if (gridPackages && packageView && beforeSaveGridPackages) {
                    packageView.getView('grid').model.attributes = beforeSaveGridPackages;
                }

                model.attributes = beforeSaveAttributes;

                this.trigger('cancel:save');
                this.afterNotValid();
                return;
            }

            if (gridPackages && packageView) {
                packageView.save();
            }

            attrs['panelsData'] = this.handlePanelsSave();

            if (!attrs) {
                this.afterNotModified(gridPackages || panelsChanges);
                this.trigger('cancel:save');
                return true;
            }

            this.beforeSave();

            this.trigger('before:save');
            model.trigger('before:save');

            let _prev = {};
            $.each(attrs, function (field, value) {
                _prev[field] = initialAttributes[field];
            });

            attrs['_prev'] = _prev;
            attrs['_silentMode'] = true;

            model.save(attrs, {
                success: function () {
                    self.afterSave();
                    let isNew = self.isNew;
                    if (self.isNew) {
                        self.isNew = false;
                    }
                    self.trigger('after:save');
                    model.trigger('after:save');

                    if (!callback) {
                        if (!skipExit) {
                            if (isNew) {
                                self.exit('create');
                            } else {
                                self.exit('save');
                            }
                        }
                    } else {
                        callback(self);
                    }
                },
                error: function (e, xhr) {
                    let statusReason = xhr.getResponseHeader('X-Status-Reason') || '';
                    if (xhr.status === 409) {
                        self.notify(false);
                        self.enableButtons();
                        self.trigger('cancel:save');
                        Espo.Ui.confirm(statusReason, {
                            confirmText: self.translate('Apply'),
                            cancelText: self.translate('Cancel')
                        }, function () {
                            attrs['_prev'] = null;
                            attrs['_ignoreConflict'] = true;
                            attrs['_silentMode'] = false;
                            model.save(attrs, {
                                success: function () {
                                    self.afterSave();
                                    self.isNew = false;
                                    self.trigger('after:save');
                                    model.trigger('after:save');
                                    if (!callback) {
                                        if (!skipExit) {
                                            self.exit('save');
                                        }
                                    } else {
                                        callback(self);
                                    }
                                },
                                patch: true
                            });
                        })
                    } else {
                        self.enableButtons();
                        self.trigger('cancel:save');

                        if (xhr.status === 304) {
                            Espo.Ui.notify(self.translate('notModified', 'messages'), 'warning', 1000 * 60 * 60 * 2, true);
                        } else {
                            Espo.Ui.notify(`${self.translate("Error")} ${xhr.status}: ${statusReason}`, "error", 1000 * 60 * 60 * 2, true);
                        }
                    }
                },
                patch: !model.isNew()
            });

            return true;
        },

        hasCompleteness() {
            return this.getMetadata().get(['scopes', this.scope, 'hasCompleteness'])
                && this.getMetadata().get(['app', 'additionalEntityParams', 'hasCompleteness']);
        }
    })
);

