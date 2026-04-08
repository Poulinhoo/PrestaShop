import testContext from '@utils/testContext';
import {expect} from 'chai';
import {createCurrencyTest, deleteCurrencyTest} from '@commonTests/BO/international/currency';

import {
  boCartRulesPage,
  boCatalogPriceRulesCreatePage,
  boCatalogPriceRulesPage,
  boDashboardPage,
  boLocalizationPage,
  boLoginPage,
  type BrowserContext,
  dataCountries,
  dataCurrencies,
  dataCustomers,
  dataGroups,
  dataProducts,
  FakerCatalogPriceRule,
  foHummingbirdCartPage,
  foHummingbirdCategoryPage,
  foHummingbirdSearchResultsPage,
  foHummingbirdHomePage,
  foHummingbirdLoginPage,
  foHummingbirdProductPage,
  type ImportContent,
  type Page,
  utilsPlaywright,
} from '@prestashop-core/ui-testing';

const baseContext: string = 'functional_BO_catalog_discounts_catalogPriceRules_CRUDCountry';

describe('BO - Catalog - Discounts : CRUD country', async () => {
  let browserContext: BrowserContext;
  let page: Page;

  const contentToImport: ImportContent = {
    importStates: true,
    importTaxes: true,
    importCurrencies: true,
    importLanguages: true,
    importUnits: true,
    updatePriceDisplayForGroups: false,
  };

  const catalogPriceRuleData: FakerCatalogPriceRule = new FakerCatalogPriceRule({
    name: 'test',
    currency: 'All currencies',
    country: 'France',
    group: 'All groups',
    reductionType: 'Amount',
    reductionTax: 'Tax included',
    fromQuantity: 1,
    reduction: 10.00,
  });
  const editCatalogPriceRuleData: FakerCatalogPriceRule = new FakerCatalogPriceRule({
    name: 'test',
    currency: 'All currencies',
    country: 'United Arab Emirates',
    group: 'All groups',
    reductionType: 'Amount',
    reductionTax: 'Tax included',
    fromQuantity: 1,
    reduction: 10.00,
  });

  before(async function () {
    browserContext = await utilsPlaywright.createBrowserContext(this.browser);
    page = await utilsPlaywright.newTab(browserContext);
  });

  after(async () => {
    await utilsPlaywright.closeBrowserContext(browserContext);
  });

  it('should login in BO', async function () {
    await testContext.addContextItem(this, 'testIdentifier', 'loginBO', baseContext);

    await boLoginPage.goTo(page, global.BO.URL);
    await boLoginPage.successLogin(page, global.BO.EMAIL, global.BO.PASSWD);

    const pageTitle = await boDashboardPage.getPageTitle(page);
    expect(pageTitle).to.contains(boDashboardPage.pageTitle);
  });

  describe('Import localization pack of United states', async () => {
    it('should go to \'International > Localization\' page', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'goToLocalizationPage', baseContext);

      await boDashboardPage.goToSubMenu(
        page,
        boDashboardPage.internationalParentLink,
        boDashboardPage.localizationLink,
      );
      await boLocalizationPage.closeSfToolBar(page);

      const pageTitle = await boLocalizationPage.getPageTitle(page);
      expect(pageTitle).to.contains(boLocalizationPage.pageTitle);
    });

    it('should import localization pack', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'importLocalizationPackUS', baseContext);

      const textResult = await boLocalizationPage.importLocalizationPack(page, 'United States', contentToImport);
      expect(textResult).to.equal(boLocalizationPage.importLocalizationPackSuccessfulMessage);
    });
  });

  describe('Import localization pack of United Arab Emirates', async () => {
    it('should import localization pack', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'importLocalizationPackUE', baseContext);

      const textResult = await boLocalizationPage.importLocalizationPack(page, 'United Arab Emirates', contentToImport);
      expect(textResult).to.equal(boLocalizationPage.importLocalizationPackSuccessfulMessage);
    });
  });

  describe('Create catalog price rule', async () => {
    it('should go to \'Catalog > Discounts\' page', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'goToDiscountsPage', baseContext);

      await boDashboardPage.goToSubMenu(
        page,
        boDashboardPage.catalogParentLink,
        boDashboardPage.discountsLink,
      );

      const pageTitle = await boCartRulesPage.getPageTitle(page);
      expect(pageTitle).to.contains(boCartRulesPage.pageTitle);
    });

    it('should go to \'Catalog Price Rules\' tab', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'goToCatalogPriceRulesTab', baseContext);

      await boCartRulesPage.goToCatalogPriceRulesTab(page);

      const pageTitle = await boCatalogPriceRulesPage.getPageTitle(page);
      expect(pageTitle).to.contains(boCatalogPriceRulesPage.pageTitle);
    });

    it('should go to add catalog price rules page', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'goAddCatalogPriceRulesPage', baseContext);

      await boCatalogPriceRulesPage.goToAddNewCatalogPriceRulePage(page);

      const pageTitle = await boCatalogPriceRulesCreatePage.getPageTitle(page);
      expect(pageTitle).to.contains(boCatalogPriceRulesCreatePage.pageTitle);
    });

    it('should create new catalog price rule', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'createCatalogPriceRule', baseContext);

      const validationMessage = await boCatalogPriceRulesCreatePage.setCatalogPriceRule(page, catalogPriceRuleData);
      expect(validationMessage).to.contains(boCatalogPriceRulesPage.successfulCreationMessage);
    });
  });

  describe('Check catalog price rule in FO', async () => {
    it('should view my shop', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'viewMyShop_1', baseContext);

      // View my shop and init pages
      page = await boCatalogPriceRulesCreatePage.viewMyShop(page);
      await foHummingbirdHomePage.changeLanguage(page, 'en');

      const isHomePage = await foHummingbirdHomePage.isHomePage(page);
      expect(isHomePage).to.eq(true);
    });

    it(`should search for the product '${dataProducts.demo_6.name}'`, async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'searchProduct', baseContext);

      await foHummingbirdHomePage.searchProduct(page, dataProducts.demo_6.name);

      const pageTitle = await foHummingbirdSearchResultsPage.getPageTitle(page);
      expect(pageTitle).to.equal(foHummingbirdSearchResultsPage.pageTitle);
    });

    it('should go to the product page', async function(){
      await testContext.addContextItem(this, 'testIdentifier', 'goToProductPage', baseContext);

      await foHummingbirdSearchResultsPage.goToProductPage(page, 1);

      const pageTitle = await foHummingbirdProductPage.getPageTitle(page);
      expect(pageTitle).to.contains(dataProducts.demo_6.name);
    });

    it('should check the discount', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'checkDiscount', baseContext);

      // Check discount percentage
      let columnValue = await foHummingbirdProductPage.getDiscountAmount(page);
      expect(columnValue).to.equal(`(Save €${catalogPriceRuleData.reduction.toFixed(2)})`);

      // Check final price
      let finalPrice = await foHummingbirdProductPage.getProductInformation(page);
      expect(finalPrice.price.toFixed(2)).to.equal(
        (
          dataProducts.demo_6.combinations[0].price - catalogPriceRuleData.reduction
        ).toFixed(2),
      );

      // Set quantity of the product
      await foHummingbirdProductPage.setQuantity(page, catalogPriceRuleData.fromQuantity);

      // Check discount value
      columnValue = await foHummingbirdProductPage.getDiscountAmount(page);
      expect(columnValue).to.equal(`(Save €${catalogPriceRuleData.reduction.toFixed(2)})`);

      // Check final price
      finalPrice = await foHummingbirdProductPage.getProductInformation(page);
      expect(finalPrice.price.toFixed(2)).to.equal(
        (
          dataProducts.demo_6.combinations[0].price - catalogPriceRuleData.reduction
        ).toFixed(2),
      );
    });

    it('should add the product to the cart', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'checkCart_1', baseContext);

      await foHummingbirdProductPage.addProductToTheCart(page);

      const pageTitle = await foHummingbirdCartPage.getPageTitle(page);
      expect(pageTitle).to.equal(foHummingbirdCartPage.pageTitle);

      const productDetail = await foHummingbirdCartPage.getProductDetail(page, 1);
      await Promise.all([
        expect(productDetail.regularPrice).to.equal(dataProducts.demo_6.combinations[0].price),
        expect(productDetail.price.toFixed(2)).to.equal(
          (
            dataProducts.demo_6.combinations[0].price - catalogPriceRuleData.reduction
          ).toFixed(2),
        ),
        expect(productDetail.discountAmount).to.equal(`-€${catalogPriceRuleData.reduction.toFixed(2)}`),
      ]);
    });
  });

  describe('Edit catalog price rules and check it in FO - Country', async ()=>{
    it('should go back to BO', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'goBackToBO', baseContext);

      page = await foHummingbirdCartPage.changePage(browserContext, 0);

      const pageTitle = await boCatalogPriceRulesPage.getPageTitle(page);
      expect(pageTitle).to.contains(boCatalogPriceRulesPage.pageTitle);
    });

    it('should edit the catalog price rules', async function(){
      await testContext.addContextItem(this, 'testIdentifier', 'editCatalogPriceRules', baseContext);

      await boCatalogPriceRulesPage.goToEditCatalogPriceRulePage(page, catalogPriceRuleData.name);

      const pageTitle = await boCatalogPriceRulesCreatePage.getPageTitle(page);
      expect(pageTitle).to.contains(boCatalogPriceRulesCreatePage.editPageTitle);

      const validationMessage = await boCatalogPriceRulesCreatePage.setCatalogPriceRule(page, editCatalogPriceRuleData);
      expect(validationMessage).to.contains(boCatalogPriceRulesPage.successfulUpdateMessage);
    });

    it('should go back to FO', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'goBackToFO', baseContext);

      page = await boCatalogPriceRulesCreatePage.changePage(browserContext, 1);

      const pageTitle = await foHummingbirdCartPage.getPageTitle(page);
      expect(pageTitle).to.contains(foHummingbirdCartPage.pageTitle);
    });

    it('should check that no reduction is displayed', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'checkNoReduction', baseContext);

      await foHummingbirdCartPage.reloadPage(page);

      const productDetail = await foHummingbirdCartPage.getProductDetail(page, 1);
      await Promise.all([
        expect(productDetail.regularPrice).to.equal(dataProducts.demo_6.combinations[0].price),
        expect(productDetail.price.toFixed(2)).to.equal(
          (
            dataProducts.demo_6.combinations[0].price
          ).toFixed(2),
        ),
      ]);
    });
  });

  describe('Edit customer country', async function(){

  });
});
